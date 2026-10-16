<?php

/*
| Every answer says how long the server took and how much of it was the database (a Server-Timing header, which
| browsers show in their network panel). It is how the site's speed is measured where it runs, apart from the network.
*/

it('says how long the server and the database took', function () {
    $timing = $this->get('/pricing')->assertOk()->headers->get('Server-Timing');

    expect($timing)->toMatch('/^app;dur=\d+(\.\d+)?, db;dur=\d+(\.\d+)?;desc="\d+ queries"$/');
});

it('says it for the API too', function () {
    expect($this->getJson('/api/v1/plans')->assertOk()->headers->get('Server-Timing'))->toStartWith('app;dur=');
});
