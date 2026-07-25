<?php

it('renders the comparador mockup publicly', function () {
    $this->get('/maqueta/comparador')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Maqueta/Comparador'));
});
