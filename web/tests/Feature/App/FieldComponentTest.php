<?php

/*
| A field whose name is a list entry (wording[late], play_products[pro]) keeps what was typed and shows its own mistake
| after a form comes back, the same as a plain field: Laravel files both under the dotted name (wording.late).
*/

/** What the last request sent, as a form that came back with mistakes has it. */
function typedBefore(array $input): void
{
    $session = app('session')->driver();
    $session->put('_old_input', $input);
    request()->setLaravelSession($session);
}

it('shows a list field’s mistake next to it and keeps what was typed', function () {
    typedBefore(['wording' => ['late' => 'Overdue!']]);

    $html = (string) $this->withViewErrors(['wording.late' => 'Too long.'])
        ->blade('<x-field name="wording[late]" id="f-late" label="Late" />');

    expect($html)->toContain('value="Overdue!"')->toContain('Too long.')->toContain('aria-invalid="true"');
});

it('keeps a plain field working as before', function () {
    typedBefore(['name' => 'Huda']);

    $html = (string) $this->withViewErrors(['name' => 'The name field is required.'])->blade('<x-field name="name" label="Name" />');

    expect($html)->toContain('value="Huda"')->toContain('The name field is required.');
});
