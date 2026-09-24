<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Services\SettingsService;

/** Admin → Settings: the small set of business-facing options declared in config/settings.php. */
final class SettingsController extends CrmController
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function index(): Response
    {
        $fields = $this->settings->fields();
        $saved = $this->settings->saved();
        $values = [];
        foreach (array_keys($fields) as $key) {
            $values[$key] = (string) ($saved[$key] ?? '');
        }

        return view_response('crm.admin.settings.index', [
            'groups' => $this->settings->groups(), 'fields' => $fields, 'values' => $values,
            'defaults' => array_map(fn (array $f): string => (string) ($this->settings->defaultFor($f) ?? ''), $fields),
            'canManage' => can('settings.manage'),
        ]);
    }

    public function update(Request $request): Response
    {
        $submitted = $request->input('s', []);
        $submitted = is_array($submitted) ? $submitted : [];
        try {
            $changed = $this->settings->update($submitted, $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), ['s' => $submitted], '/admin/settings');
        }
        flash('status', $changed === [] ? 'Nothing changed.' : 'Settings saved (' . count($changed) . ' changed).');

        return Response::redirect('/admin/settings');
    }
}
