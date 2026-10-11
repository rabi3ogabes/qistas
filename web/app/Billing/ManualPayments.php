<?php

namespace App\Billing;

use App\Actions\Admin\ChangePlan;
use App\Models\ManualPaymentReceipt;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\Notifier;
use App\Notifications\Push\PushMessage;
use App\Support\Audit;
use App\Support\ImageSanitizer;
use App\Support\InLanguage;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying for a plan by bank transfer (Win Plan PP13). The owner sends the transfer's reference and receipt for a plan
 * and a period, priced from the plan; one request waits at a time, and a retry with the same key is the same request.
 * A super admin approves it, putting the workspace on the plan for the period paid (added to any time left on the same
 * plan), or rejects it with a reason. When automatic approval is on, it is approved as it arrives. The owner is told
 * either way, in their own language.
 */
final class ManualPayments
{
    /** Receipts are kept in the database, so they stay small. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private readonly BillingSettings $settings,
        private readonly ChangePlan $changePlan,
        private readonly Notifier $notifier,
        private readonly CurrentTenant $current,
    ) {}

    /** @throws ValidationException naming the field at fault (plan, period, method, receipt, request) */
    public function submit(
        Tenant $tenant,
        string $planKey,
        string $period,
        string $method,
        string $reference,
        UploadedFile $receipt,
        User $by,
        ?string $idempotencyKey = null,
    ): ManualPaymentRequest {
        $request = $this->current->use($tenant, function () use ($tenant, $planKey, $period, $method, $reference, $receipt, $by, $idempotencyKey): ManualPaymentRequest {
            if ($idempotencyKey !== null && ($existing = ManualPaymentRequest::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }

            $plan = Plan::query()->where('key', $planKey)->where('is_default', false)->first();
            if (! in_array($period, ManualPaymentRequest::PERIODS, true)) {
                throw ValidationException::withMessages(['period' => __('Choose to pay for a month or for a year.')]);
            }
            $price = $plan === null ? null : ($period === 'year' ? $plan->price_yearly : $plan->price_monthly);
            if ($plan === null || $price === null || ! Money::isPositive($price)) {
                throw ValidationException::withMessages(['plan' => __('Choose a paid plan.')]);
            }
            if (! in_array($method, ManualPaymentRequest::METHODS, true)) {
                throw ValidationException::withMessages(['method' => __('Choose how you paid.')]);
            }
            [$mime, $bytes] = $this->readReceipt($receipt);
            if (ManualPaymentRequest::query()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['request' => __('A transfer is already waiting for the Qistas team. You can send another once it is decided.')]);
            }

            return DB::transaction(function () use ($tenant, $plan, $period, $method, $reference, $price, $mime, $bytes, $by, $idempotencyKey): ManualPaymentRequest {
                $request = (new ManualPaymentRequest)->forceFill([
                    'plan_id' => $plan->id,
                    'period' => $period,
                    'amount' => $price,
                    'currency' => $plan->currency,
                    'method' => $method,
                    'reference' => $reference,
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'created_by_user_id' => $by->id,
                ]);
                $request->save();
                (new ManualPaymentReceipt)->forceFill([
                    'manual_payment_request_id' => $request->id,
                    'mime' => $mime,
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'data' => base64_encode($bytes),
                ])->save();

                Audit::record('billing.manual_requested', $request, [
                    'plan' => $plan->key, 'period' => $period, 'amount' => Money::add($price, '0', 2), 'currency' => $plan->currency, 'reference' => $reference,
                ], $tenant->id, $by->id);

                return $request;
            });
        });

        if ($request->wasRecentlyCreated && $this->settings->autoActivate()) {
            return $this->approve($request, null);
        }

        return $request;
    }

    /**
     * Put the workspace on the plan for the period paid.
     *
     * @param  User|null  $by  the super admin; null when automatic approval decided
     *
     * @throws ValidationException (request) when it was already decided
     */
    public function approve(ManualPaymentRequest $request, ?User $by, ?string $note = null): ManualPaymentRequest
    {
        $approved = DB::transaction(function () use ($request, $by, $note): ManualPaymentRequest {
            $locked = $this->lockPending($request);
            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            $plan = Plan::query()->findOrFail($locked->plan_id);

            // Paid before the end of the same plan: the new period starts where the current one stops.
            $subscription = $tenant->subscription()->first();
            $end = $subscription?->current_period_end;
            $from = $subscription !== null && $subscription->isCurrent() && $subscription->plan_id === $plan->id && $end !== null && $end->isFuture()
                ? Carbon::parse($end) : now();
            $until = $locked->period === 'year' ? $from->copy()->addYearNoOverflow() : $from->copy()->addMonthNoOverflow();

            $reason = 'Bank transfer '.$locked->reference.($by === null ? ' (approved automatically)' : '');
            $this->changePlan->handle($tenant, $plan, $until, $reason, $by, 'manual');

            $locked->forceFill(['status' => 'approved', 'decided_by_user_id' => $by?->id, 'decided_at' => now(), 'note' => $note])->save();
            Audit::record('billing.manual_approved', $locked, [
                'plan' => $plan->key, 'period' => $locked->period, 'until' => $until->toDateString(), 'automatic' => $by === null,
            ], $tenant->id, $by?->id);

            return $locked;
        });

        $this->tell($approved, 'billing.approved');

        return $approved;
    }

    /** @throws ValidationException (request) when it was already decided */
    public function reject(ManualPaymentRequest $request, User $by, string $note): ManualPaymentRequest
    {
        $rejected = DB::transaction(function () use ($request, $by, $note): ManualPaymentRequest {
            $locked = $this->lockPending($request);
            $locked->forceFill(['status' => 'rejected', 'decided_by_user_id' => $by->id, 'decided_at' => now(), 'note' => $note])->save();
            Audit::record('billing.manual_rejected', $locked, ['reason' => $note], $locked->tenant_id, $by->id);

            return $locked;
        });

        $this->tell($rejected, 'billing.rejected');

        return $rejected;
    }

    private function lockPending(ManualPaymentRequest $request): ManualPaymentRequest
    {
        // Platform staff decide across workspaces: the request is found by its id alone.
        $locked = ManualPaymentRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['request' => __('This request has already been decided.')]);
        }

        return $locked;
    }

    /** The owner who sent it hears the decision in their inbox and on their phone. */
    private function tell(ManualPaymentRequest $request, string $type): void
    {
        $owner = User::query()->find($request->created_by_user_id);
        $tenant = Tenant::query()->find($request->tenant_id);
        if ($owner === null || $tenant === null) {
            return;
        }

        $this->current->use($tenant, function () use ($owner, $tenant, $request, $type): void {
            $message = InLanguage::run((string) ($owner->locale ?: 'en'), function () use ($tenant, $request, $type): PushMessage {
                if ($type === 'billing.rejected') {
                    return new PushMessage($type, __('Payment not confirmed'), (string) $request->note, ['route' => '/plans']);
                }
                $plan = $tenant->currentPlan();
                $until = $tenant->subscription()->first()?->current_period_end;

                return new PushMessage($type, __(':plan is on', ['plan' => $plan->name]), __('Your workspace is on :plan until :date.', [
                    'plan' => $plan->name, 'date' => $until === null ? '' : $until->translatedFormat('j F Y'),
                ]), ['route' => '/plans']);
            });
            $this->notifier->notify($owner, $message, []);
        });
    }

    /**
     * The receipt as it will be kept: what the bytes are (never the name the browser gave), within the size allowed, and
     * a picture drawn again without its hidden data.
     *
     * @return array{0: string, 1: string} the type and the bytes
     *
     * @throws ValidationException (receipt)
     */
    private function readReceipt(UploadedFile $file): array
    {
        $path = $file->isValid() ? $file->getRealPath() : false;
        if ($path === false) {
            throw ValidationException::withMessages(['receipt' => __('The file could not be uploaded. Please try again.')]);
        }
        $bytes = (string) file_get_contents($path);
        $mime = $bytes === '' ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, self::TYPES, true)) {
            throw ValidationException::withMessages(['receipt' => __('Send the receipt as a picture (JPG or PNG) or a PDF.')]);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['receipt' => __('The receipt is larger than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024])]);
        }
        if ($mime !== 'application/pdf') {
            $bytes = ImageSanitizer::clean($bytes, $mime, 2400, 2400)
                ?? throw ValidationException::withMessages(['receipt' => __('This file could not be read as a picture.')]);
        }

        return [$mime, $bytes];
    }
}
