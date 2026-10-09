<?php

test('hello returns world', function () {
    $this->getJson('/api/v1/hello')
        ->assertOk()
        ->assertExactJson(['message' => 'world']);
});
