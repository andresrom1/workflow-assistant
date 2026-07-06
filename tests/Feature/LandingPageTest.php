<?php

it('renders the landing page publicly', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Landing/Index')
            ->where('waQuoteUrl', null)
            ->where('appDownloadUrl', null));
});

it('builds the wa.me quote URL from the public number', function () {
    config(['whatsapp.public_number' => '5493510000000']);
    config(['whatsapp.app_download_url' => 'https://play.google.com/store/apps/details?id=ar.mango.app']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Landing/Index')
            ->where('waQuoteUrl', fn ($url) => str_starts_with((string) $url, 'https://wa.me/5493510000000?text='))
            ->where('appDownloadUrl', 'https://play.google.com/store/apps/details?id=ar.mango.app'));
});
