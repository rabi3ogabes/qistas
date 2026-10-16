<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Your profile, on the web (/app/profile) and in the app (/api/v1/me): your name, e-mail, phone, language and photo, and
| your password. A new e-mail is verified again; the password changes only with the current one; a photo is drawn again
| without its hidden data and kept small.
*/

function photoUpload(string $name = 'me.jpg', int $width = 900, int $height = 700): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

describe('on the web', function () {
    it('shows what can be changed, linked from the account menu', function () {
        [$owner] = owner();
        $owner->forceFill(['phone' => '+966501234567'])->save();

        // From the account menu on a wide screen and from "More" on a phone.
        $page = $this->actingAs($owner)->get('/app')->assertSee('Your profile')->getContent();
        expect(substr_count($page, 'href="'.route('app.profile').'"'))->toBe(2);
        $this->actingAs($owner)->get('/app/profile')->assertOk()
            ->assertSee($owner->name)->assertSee($owner->email)->assertSee('+966501234567')
            ->assertSee('Your details')->assertSee('Password')->assertSee('Photo')
            ->assertSee('العربية');
    });

    it('saves the name, phone and language', function () {
        [$owner] = owner();

        $this->actingAs($owner)->put('/app/profile', ['name' => 'Huda Saleh', 'email' => $owner->email, 'phone' => '٠٥٥١١١٢٢٠١', 'locale' => 'ar'])
            ->assertRedirect('/app/profile')->assertSessionHas('status')->assertSessionHasNoErrors();

        expect($owner->fresh())->name->toBe('Huda Saleh')->phone->toBe('0551112201')->locale->toBe('ar');
    });

    it('verifies a new e-mail again and sends the link', function () {
        Notification::fake();
        [$owner] = owner();

        $this->actingAs($owner)->put('/app/profile', ['name' => $owner->name, 'email' => 'New@Example.com'])->assertSessionHasNoErrors();

        expect($owner->fresh())->email->toBe('new@example.com')->email_verified_at->toBeNull();
        Notification::assertSentTo($owner->fresh(), VerifyEmailQueued::class);
    });

    it('says what is wrong, field by field, and keeps what was typed', function () {
        User::factory()->create(['email' => 'taken@example.com']);
        [$owner] = owner();

        $this->actingAs($owner)->from('/app/profile')->put('/app/profile', ['name' => '', 'email' => 'taken@example.com', 'phone' => '12'])
            ->assertRedirect('/app/profile')
            ->assertSessionHasErrorsIn('updateProfileInformation', ['name', 'email', 'phone']);

        $this->actingAs($owner)->get('/app/profile')->assertSee('value="12"', false);
    });

    it('lets the phone be cleared', function () {
        [$owner] = owner();
        $owner->forceFill(['phone' => '+966501234567'])->save();

        $this->actingAs($owner)->put('/app/profile', ['name' => $owner->name, 'email' => $owner->email, 'phone' => ''])->assertSessionHasNoErrors();

        expect($owner->fresh()->phone)->toBeNull();
    });

    it('changes the password only with the current one and a strong new one', function () {
        [$owner] = owner();
        $owner->forceFill(['password' => TEST_PASSWORD])->save();

        $this->actingAs($owner)->put('/app/profile/password', ['current_password' => 'wrong', 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->actingAs($owner)->put('/app/profile/password', ['current_password' => TEST_PASSWORD, 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
            ->assertRedirect('/app/profile')->assertSessionHas('status', 'Your password is changed.');

        expect(Hash::check('N3w!Passw0rd#1', $owner->fresh()->password))->toBeTrue();
    });

    it('keeps a photo drawn again and small, shows it, and takes it away', function () {
        [$owner] = owner();

        $this->actingAs($owner)->post('/app/profile/photo', ['photo' => photoUpload()])->assertRedirect('/app/profile')->assertSessionHasNoErrors();

        $photo = $this->actingAs($owner)->get('/app/profile/photo')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        [$width, $height] = getimagesizefromstring($photo->getContent());
        expect(max($width, $height))->toBeLessThanOrEqual(400);
        $this->actingAs($owner)->get('/app/profile')->assertSee(route('app.profile.photo'), false);

        $this->actingAs($owner)->delete('/app/profile/photo')->assertRedirect('/app/profile');
        $this->actingAs($owner)->get('/app/profile/photo')->assertNotFound();
    });

    it('refuses a file that is not a picture', function () {
        [$owner] = owner();

        $this->actingAs($owner)->post('/app/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('me.jpg', '%PDF-1.4 not a picture')])
            ->assertSessionHasErrors(['photo' => 'Choose a photo: a JPEG, PNG or WebP picture.']);
        // A picture of another kind (here a GIF named .jpg) is not taken either: what a file is comes from its bytes.
        $this->actingAs($owner)->post('/app/profile/photo', ['photo' => UploadedFile::fake()->image('me.gif', 300, 300)])
            ->assertSessionHasErrors(['photo' => 'Choose a photo: a JPEG, PNG or WebP picture.']);
        $this->actingAs($owner)->get('/app/profile/photo')->assertNotFound();
    });

    it('notes a change in the activity log without the values themselves', function () {
        [$owner, $tenant] = owner();

        $this->actingAs($owner)->put('/app/profile', ['name' => 'Huda Saleh', 'email' => $owner->email, 'phone' => '0551112201']);

        $row = AuditLog::query()->where('action', 'profile.updated')->sole();
        expect($row->user_id)->toBe($owner->id)
            ->and(json_encode($row->changes))->toContain('phone')->not->toContain('0551112201');
    });
});

describe('in the app', function () {
    it('tells the app the phone and the photo with the rest of the profile', function () {
        [$owner] = apiOwner();
        $owner->forceFill(['phone' => '+966501234567'])->save();

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.user.phone', '+966501234567')
            ->assertJsonPath('data.user.photo', null);
    });

    it('saves the profile and answers with it', function () {
        [$owner] = apiOwner();

        $this->putJson('/api/v1/me', ['name' => 'Huda Saleh', 'email' => $owner->email, 'phone' => '0551112201', 'locale' => 'fr'])->assertOk()
            ->assertJsonPath('data.user.name', 'Huda Saleh')
            ->assertJsonPath('data.user.phone', '0551112201')
            ->assertJsonPath('data.user.locale', 'fr');

        $this->putJson('/api/v1/me', ['name' => '', 'email' => 'not-an-email'])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email'], 'error.fields');
    });

    it('changes the password', function () {
        [$owner] = apiOwner();
        $owner->forceFill(['password' => TEST_PASSWORD])->save();

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password', 'error.fields');
        $this->putJson('/api/v1/me/password', ['current_password' => TEST_PASSWORD, 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
            ->assertNoContent();

        expect(Hash::check('N3w!Passw0rd#1', $owner->fresh()->password))->toBeTrue();
    });

    it('takes a photo and takes it away', function () {
        [$owner] = apiOwner();

        $this->post('/api/v1/me/photo', ['photo' => photoUpload('me.png')], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.user.photo', fn (string $photo) => str_starts_with($photo, 'data:image/png;base64,'));

        $this->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.user.photo', null);
    });

    it('keeps one person’s photo to themselves', function () {
        [$owner] = owner();
        $this->actingAs($owner)->post('/app/profile/photo', ['photo' => photoUpload()]);
        [$other] = owner();

        $this->actingAs($other)->get('/app/profile/photo')->assertNotFound();
        Sanctum::actingAs($other, ['app']);
        $this->getJson('/api/v1/me')->assertJsonPath('data.user.photo', null);
    });
});
