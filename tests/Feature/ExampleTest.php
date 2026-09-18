<?php

test('the informational landing page describes the API and its owner', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('Julio Serpone', escape: false)
        ->assertSee('github.com/julioserpone/ride-efficiency-api', escape: false)
        ->assertSee('/api/v1/stats/summary', escape: false);
});

test('the landing page highlights the api consumers', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('ride-efficiency-frontend', escape: false)
        ->assertSee('ride-efficiency-mobile', escape: false);
});
