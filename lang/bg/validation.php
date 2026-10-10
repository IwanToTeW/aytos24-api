<?php

/*
 * Bulgarian validation messages. Rules without a translation here fall back to English
 * (app.fallback_locale). Clients branch on field names and HTTP status, never on these texts.
 */

return [

    'accepted' => 'Полето :attribute трябва да бъде прието.',
    'array' => 'Полето :attribute трябва да бъде масив.',
    'between' => [
        'array' => 'Полето :attribute трябва да съдържа между :min и :max елемента.',
        'numeric' => 'Полето :attribute трябва да бъде между :min и :max.',
        'string' => 'Полето :attribute трябва да бъде между :min и :max знака.',
    ],
    'boolean' => 'Полето :attribute трябва да бъде вярно или невярно.',
    'confirmed' => 'Потвърждението на полето :attribute не съвпада.',
    'date' => 'Полето :attribute трябва да бъде валидна дата.',
    'email' => 'Полето :attribute трябва да бъде валиден имейл адрес.',
    'exists' => 'Избраната стойност на :attribute е невалидна.',
    'in' => 'Избраната стойност на :attribute е невалидна.',
    'integer' => 'Полето :attribute трябва да бъде цяло число.',
    'max' => [
        'array' => 'Полето :attribute не трябва да съдържа повече от :max елемента.',
        'numeric' => 'Полето :attribute не трябва да бъде по-голямо от :max.',
        'string' => 'Полето :attribute не трябва да бъде по-дълго от :max знака.',
    ],
    'min' => [
        'array' => 'Полето :attribute трябва да съдържа поне :min елемента.',
        'numeric' => 'Полето :attribute трябва да бъде поне :min.',
        'string' => 'Полето :attribute трябва да бъде поне :min знака.',
    ],
    'numeric' => 'Полето :attribute трябва да бъде число.',
    'password' => [
        'letters' => 'Полето :attribute трябва да съдържа поне една буква.',
        'mixed' => 'Полето :attribute трябва да съдържа поне една главна и една малка буква.',
        'numbers' => 'Полето :attribute трябва да съдържа поне една цифра.',
        'symbols' => 'Полето :attribute трябва да съдържа поне един символ.',
        'uncompromised' => 'Тази :attribute е изтекла при пробив на данни. Моля, изберете друга :attribute.',
    ],
    'prohibited' => 'Полето :attribute не е разрешено.',
    'regex' => 'Форматът на полето :attribute е невалиден.',
    'required' => 'Полето :attribute е задължително.',
    'string' => 'Полето :attribute трябва да бъде текст.',
    'unique' => 'Този :attribute вече е зает.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'име',
        'email' => 'имейл адрес',
        'password' => 'парола',
        'password_confirmation' => 'потвърждение на паролата',
        'phone' => 'телефон',
        'remember' => 'запомни ме',
        'token' => 'код',
    ],

];
