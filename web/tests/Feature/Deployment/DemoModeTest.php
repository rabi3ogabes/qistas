<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;

describe('on a real site', function () {
    it('shows no demo notice and no demo sign-in', function () {
        config(['qistas.demo' => false]);

        $this->get('/')->assertOk()->assertDontSee('This is a demo');
        $this->get('/login')->assertOk()->assertDontSee('This is a demo')->assertDontSee(DemoSeeder::EMAIL)->assertDontSee(DemoSeeder::PASSWORD);
    });
});

describe('while running as a throw-away demo', function () {
    beforeEach(fn () => config(['qistas.demo' => true]));

    it('says so on every kind of page', function () {
        $this->get('/')->assertOk()->assertSee('This is a demo');
        $this->get('/pricing')->assertOk()->assertSee('This is a demo');
        $this->get('/login')->assertOk()->assertSee('This is a demo');
        $this->get('/register')->assertOk()->assertSee('This is a demo');

        [$user] = owner();
        $this->actingAs($user)->get('/app')->assertOk()->assertSee('This is a demo');
    });

    it('shows the demo account on the sign-in page', function () {
        $this->get('/login')->assertSee(DemoSeeder::EMAIL)->assertSee(DemoSeeder::PASSWORD);
    });

    it('lets the demo account sign in once it exists', function () {
        $this->artisan('qistas:setup', ['--demo' => true])->assertSuccessful();

        $this->post('/login', ['email' => DemoSeeder::EMAIL, 'password' => DemoSeeder::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs(User::where('email', DemoSeeder::EMAIL)->sole());
    });

    it('says so in every language', function (string $locale) {
        $this->get('/?lang='.$locale)->assertOk()->assertDontSee('This is a demo');
    })->with(['ar', 'fr', 'es', 'ur']);
});
