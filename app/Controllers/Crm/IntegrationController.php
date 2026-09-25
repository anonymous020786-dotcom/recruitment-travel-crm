<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Integrations\Credentials;
use App\Payments\GatewayRegistry;
use App\Storage\ObjectStorage;

/**
 * Admin → Integrations: set, rotate, switch off and clear the keys and secrets of every third-party service. Super admin only
 * (`integrations.*`); writes need a fresh password confirmation. Secrets are write-only: the pages never contain one.
 */
final class IntegrationController extends CrmController
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly GatewayRegistry $gateways,
        private readonly ObjectStorage $objects,
    ) {
    }

    public function index(): Response
    {
        $services = [];
        foreach ($this->credentials->services() as $key => $def) {
            $services[$def['group']][] = ['key' => $key, 'label' => $def['label'], 'description' => $def['description']] + $this->credentials->status($key);
        }

        return $this->private(view_response('crm.admin.integrations.index', ['groups' => $this->credentials->groups(), 'services' => $services]));
    }

    public function show(string $service): Response
    {
        $def = $this->credentials->service($service) ?? abort(404, 'Unknown integration.');

        return $this->private(view_response('crm.admin.integrations.show', [
            'key' => $service, 'def' => $def, 'fields' => $this->credentials->view($service), 'status' => $this->credentials->status($service),
            'enabled' => $this->credentials->isEnabled($service), 'canManage' => can('integrations.manage'),
            'webhookUrl' => GatewayRegistry::knows($service) ? rtrim((string) config('app.url'), '/') . '/webhooks/' . $service : null,
            'testable' => GatewayRegistry::knows($service) || ObjectStorage::isRemote($service),
        ]));
    }

    public function update(Request $request, string $service): Response
    {
        $def = $this->credentials->service($service) ?? abort(404, 'Unknown integration.');
        $submitted = $request->input('f', []);
        $input = is_array($submitted) ? array_intersect_key($submitted, $def['fields']) : [];
        $input['clear'] = array_values(array_filter((array) $request->input('clear', []), static fn ($n): bool => is_string($n) && isset($def['fields'][$n])));
        $input[Credentials::ENABLED] = (string) $request->input('enabled', '') === '1' ? '1' : '0';

        try {
            $changed = $this->credentials->save($service, $input, $this->currentUser());
        } catch (ValidationException $e) {
            // never flash what was typed back: it may contain a secret
            return redirect_with_errors($e->errors(), [], '/admin/integrations/' . $service);
        }
        flash('status', $changed === [] ? 'Nothing changed.' : $def['label'] . ' saved (' . count($changed) . ' setting' . (count($changed) === 1 ? '' : 's') . ' changed).');

        return Response::redirect('/admin/integrations/' . $service);
    }

    /** "Test connection": a harmless authenticated call to the provider with the saved credentials. Nothing is changed anywhere. */
    public function test(string $service): Response
    {
        $def = $this->credentials->service($service) ?? abort(404, 'Unknown integration.');
        $result = match (true) {
            GatewayRegistry::knows($service) => $this->gateways->usable($service)?->test() ?? ['ok' => false, 'message' => 'Save every required field and switch the service on first.'],
            ObjectStorage::isRemote($service) => ($c = $this->objects->client($service)) !== null ? $c->checkBucket() : ['ok' => false, 'message' => 'Save every required field and switch the service on first.'],
            default => ['ok' => false, 'message' => $def['label'] . ' has no live connection test — the tool itself reports problems when it is used.'],
        };
        $result['ok'] ? flash('status', (string) $result['message']) : session()?->flash('error_toast', (string) $result['message']);

        return Response::redirect('/admin/integrations/' . $service);
    }

    public function reset(string $service): Response
    {
        $def = $this->credentials->service($service) ?? abort(404, 'Unknown integration.');
        $n = $this->credentials->reset($service, $this->currentUser());
        flash('status', $n > 0 ? $def['label'] . ': everything saved in the panel was removed.' : 'Nothing was saved for ' . $def['label'] . '.');

        return Response::redirect('/admin/integrations/' . $service);
    }

    private function private(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store, private');
    }
}
