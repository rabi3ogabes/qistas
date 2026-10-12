{{--
    The paperwork kept with a contract (Win Plan K5): up to three guarantors and photos of the ID, the signed contract and
    the invoice. ID numbers are masked and shown only to owners, managers and accountants. An ordinary photo is shown
    through a link that lasts five minutes; an ID photo is a private tile, opened one at a time (and written to the
    audit log). Each form keeps its own errors.
--}}
@php
    $kinds = collect(\App\Support\FileKind::CONTRACT_PHOTOS)->mapWithKeys(fn ($kind) => [$kind->value => $kind->photoLabel()]);
@endphp
@if ($paperwork['shown'])
    <section class="card" aria-labelledby="paperwork-title">
        <div class="card-head"><h2 id="paperwork-title">{{ __('Paperwork') }}</h2></div>
        <div class="card-pad stack paperwork">
            <div class="stack-sm">
                <h3 class="paperwork-heading">{{ __('Guarantors') }}</h3>
                @forelse ($guarantors as $guarantor)
                    <div class="guarantor">
                        <div class="guarantor-who">
                            <strong>{{ $guarantor->name }}</strong>
                            @if ($guarantor->relation)<span class="muted">{{ $guarantor->relation }}</span>@endif
                        </div>
                        <div class="guarantor-facts">
                            <a class="link money" dir="ltr" href="tel:{{ $guarantor->phone }}">{{ $guarantor->phone }}</a>
                            @if ($paperwork['seesIds'] && $guarantor->national_id)
                                <span class="money muted" dir="ltr" title="{{ __('ID number') }}">{{ $guarantor->maskedNationalId() }}</span>
                            @endif
                        </div>
                        @if ($paperwork['canAdd'] || $paperwork['canRemove'])
                            <div class="guarantor-actions">
                                @if ($paperwork['canAdd'])
                                    @php $bag = 'guarantor-'.$guarantor->id; @endphp
                                    <details class="confirm-inline" @if ($errors->getBag($bag)->any()) open @endif>
                                        <summary class="btn btn-ghost btn-sm"><x-icon name="pencil" :size="16" /> {{ __('Edit') }}</summary>
                                        <form method="POST" action="{{ route('app.guarantors.update', $guarantor) }}" class="form">
                                            @csrf
                                            @method('PUT')
                                            <x-field name="name" :bag="$bag" :id="$bag.'-name'" :label="__('Name')" :value="$guarantor->name" required autocomplete="off" />
                                            <x-field name="phone" :bag="$bag" :id="$bag.'-phone'" type="tel" :label="__('Phone')" :value="$guarantor->phone" required inputmode="tel" dir="ltr" autocomplete="off" />
                                            <x-field name="relation" :bag="$bag" :id="$bag.'-relation'" :label="__('How they know the customer (optional)')" :value="$guarantor->relation" maxlength="60" autocomplete="off" />
                                            <x-field name="national_id" :bag="$bag" :id="$bag.'-id'" :label="__('ID number (optional)')" :hint="$guarantor->national_id ? __('Leave empty to keep the one saved.') : null" inputmode="numeric" dir="ltr" autocomplete="off" />
                                            @if ($guarantor->national_id)
                                                <label class="check"><input type="checkbox" name="remove_national_id" value="1"> <span>{{ __('Remove the saved ID number') }}</span></label>
                                            @endif
                                            <button class="btn btn-sm" type="submit">{{ __('Save') }}</button>
                                        </form>
                                    </details>
                                @endif
                                @if ($paperwork['canRemove'])
                                    <form method="POST" action="{{ route('app.guarantors.destroy', $guarantor) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-ghost btn-sm danger-text" type="submit" aria-label="{{ __('Remove :name', ['name' => $guarantor->name]) }}"><x-icon name="trash" :size="16" /> {{ __('Remove') }}</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="muted">{{ __('No guarantors on this contract.') }}</p>
                @endforelse

                @if ($paperwork['canAdd'] && $paperwork['roomForGuarantors'])
                    <details class="confirm" @if ($errors->getBag('guarantor')->any()) open @endif>
                        <summary class="btn btn-quiet btn-sm"><x-icon name="userPlus" :size="16" /> {{ __('Add a guarantor') }}</summary>
                        <form method="POST" action="{{ route('app.contracts.guarantors.store', $contract) }}" class="form">
                            @csrf
                            <x-field name="name" bag="guarantor" id="guarantor-new-name" :label="__('Name')" required autocomplete="off" />
                            <x-field name="phone" bag="guarantor" id="guarantor-new-phone" type="tel" :label="__('Phone')" required inputmode="tel" dir="ltr" autocomplete="off" />
                            <x-field name="relation" bag="guarantor" id="guarantor-new-relation" :label="__('How they know the customer (optional)')" :hint="__('For example: brother, employer, neighbour.')" maxlength="60" autocomplete="off" />
                            <x-field name="national_id" bag="guarantor" id="guarantor-new-id" :label="__('ID number (optional)')" :hint="__('Kept encrypted, and shown with all but the last three digits hidden.')" inputmode="numeric" dir="ltr" autocomplete="off" />
                            @if ($errors->getBag('guarantor')->has('guarantors'))<p class="field-error" role="alert">{{ $errors->getBag('guarantor')->first('guarantors') }}</p>@endif
                            <button class="btn btn-sm" type="submit">{{ __('Add the guarantor') }}</button>
                        </form>
                    </details>
                @endif
            </div>

            <div class="stack-sm">
                <h3 class="paperwork-heading">{{ __('Photos') }}</h3>
                @if ($photos->isEmpty())
                    <p class="muted">{{ __('No photos on this contract.') }}</p>
                @else
                    <ul class="photo-grid" role="list">
                        @foreach ($photos as $photo)
                            <li class="photo-tile">
                                @if ($photo->kind->audited())
                                    <a class="photo-frame photo-private" href="{{ route('app.files.show', $photo) }}" target="_blank" rel="noopener">
                                        <x-icon name="lock" :size="22" />
                                        <span>{{ __('Open') }}</span>
                                    </a>
                                @else
                                    <a class="photo-frame" href="{{ $photoLinks[$photo->id] }}" target="_blank" rel="noopener">
                                        <img src="{{ $photoLinks[$photo->id] }}" alt="{{ $photo->kind->photoLabel() }}" loading="lazy" decoding="async">
                                    </a>
                                @endif
                                <div class="photo-caption">
                                    <span>{{ $photo->kind->photoLabel() }}</span>
                                    @if ($paperwork['canRemove'])
                                        <form method="POST" action="{{ route('app.contracts.photos.destroy', [$contract, $photo]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-icon btn-sm" type="submit" aria-label="{{ __('Remove this photo') }}" title="{{ __('Remove this photo') }}"><x-icon name="trash" :size="16" /></button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($paperwork['canAdd'] && $paperwork['roomForPhotos'])
                    <form method="POST" action="{{ route('app.contracts.photos.store', $contract) }}" enctype="multipart/form-data" class="form photo-form">
                        @csrf
                        <div class="field">
                            <label for="photo-kind">{{ __('What it shows') }}</label>
                            <select id="photo-kind" name="kind" required>
                                @foreach ($kinds as $value => $label)
                                    <option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="photo-file">{{ __('Photo') }}</label>
                            <input id="photo-file" type="file" name="file" accept="image/jpeg,image/png" required>
                            @if ($errors->getBag('photo')->any())<p class="field-error" role="alert">{{ $errors->getBag('photo')->first() }}</p>@endif
                        </div>
                        <p class="field-hint">{{ __('Location and camera details are removed before the photo is kept.') }}</p>
                        <button class="btn btn-quiet btn-sm" type="submit"><x-icon name="upload" :size="16" /> {{ __('Keep the photo') }}</button>
                    </form>
                @endif
            </div>

            <div class="stack-sm">
                <h3 class="paperwork-heading">{{ __('The customer’s signature') }}</h3>
                @if ($customerSignature)
                    <figure class="signature-shown">
                        <img src="{{ $customerSignature->dataUri() }}" alt="{{ __('The customer’s signature') }}">
                        <figcaption class="field-hint">
                            {{ $customerSignature->method === 'link'
                                ? __('Signed on their own phone on :date', ['date' => \App\Support\Dates::day($customerSignature->signed_at)])
                                : __('Signed in person on :date', ['date' => \App\Support\Dates::day($customerSignature->signed_at)]) }}
                        </figcaption>
                    </figure>
                @else
                    <p class="muted">{{ __('Not signed yet.') }}</p>
                @endif

                @if ($link = session('signatureLink'))
                    @php
                        $wa = \App\Reminders\WhatsApp::number((string) $contract->customer?->phone, (string) app(\App\Tenancy\CurrentTenant::class)->get()?->country);
                        $message = __('Please read and sign your contract :reference here: :url', ['reference' => $contract->reference(), 'url' => $link['url']]);
                        $codeMessage = __('Your code to sign contract :reference: :code', ['reference' => $contract->reference(), 'code' => $link['code']]);
                    @endphp
                    <div class="signing-link" role="status" data-code="{{ $link['code'] }}">
                        <p><strong>{{ __('Send the link and the code apart') }}</strong></p>
                        <p class="field-hint">{{ __('The link works once, for a day. Without the code it opens nothing, so a forwarded link signs nothing.') }}</p>
                        <p class="signing-code money" dir="ltr">{{ $link['code'] }}</p>
                        <p class="signing-url money" dir="ltr">{{ $link['url'] }}</p>
                        <div class="row-actions">
                            @if ($wa)
                                <a class="btn btn-quiet btn-sm" href="https://wa.me/{{ $wa }}?text={{ rawurlencode($message) }}" target="_blank" rel="noopener"><x-icon name="message" :size="16" /> {{ __('Send the link on WhatsApp') }}</a>
                            @endif
                            <a class="btn btn-quiet btn-sm" href="sms:{{ $contract->customer?->phone }}?body={{ rawurlencode($codeMessage) }}"><x-icon name="send" :size="16" /> {{ __('Send the code by text message') }}</a>
                        </div>
                    </div>
                @endif

                @if ($paperwork['canAdd'])
                    <details class="confirm" @if ($errors->getBag('signature')->any()) open @endif>
                        <summary class="btn btn-quiet btn-sm"><x-icon name="pencil" :size="16" /> {{ $customerSignature ? __('Sign again') : __('Sign on this screen') }}</summary>
                        <form method="POST" action="{{ route('app.contracts.signature.store', $contract) }}" class="signature-pad" data-signature-pad>
                            @csrf
                            <input type="hidden" name="drawing" value="">
                            <canvas aria-label="{{ __('Sign here with your finger, a pen or the mouse') }}" role="img"></canvas>
                            <p class="field-hint">{{ __('Hand the screen to the customer to sign here.') }}</p>
                            @if ($errors->getBag('signature')->any())<p class="field-error" role="alert">{{ $errors->getBag('signature')->first() }}</p>@endif
                            <div class="form-actions">
                                <button class="btn btn-sm" type="submit" data-signature-save disabled>{{ __('Keep the signature') }}</button>
                                <button class="btn btn-ghost btn-sm" type="button" data-signature-clear>{{ __('Start again') }}</button>
                            </div>
                        </form>
                    </details>
                    <form method="POST" action="{{ route('app.contracts.signature-link.store', $contract) }}">
                        @csrf
                        <button class="btn btn-quiet btn-sm" type="submit"><x-icon name="smartphone" :size="16" /> {{ __('Send a link to sign on their phone') }}</button>
                    </form>
                @endif
            </div>

            <div class="stack-sm">
                <h3 class="paperwork-heading">{{ __('Documents') }}</h3>
                <div class="row-actions">
                    <x-document-menu id="contract-agreement" :action="route('app.contracts.agreement', $contract)" :label="__('Contract')" :sections="['signature']" :summary="false" />
                    @if ($paperwork['promissoryNote'])
                        <x-document-menu id="promissory-note" :action="route('app.contracts.promissory-note', $contract)" :label="__('Promissory note')" :sections="['signature']" :summary="false" />
                    @endif
                </div>
            </div>
        </div>
    </section>
@endif
