<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Firewall;
use App\Security\FirewallAdmin;
use App\Security\Waf\Signatures;

/** Admin → Security → Firewall (super admin, `security.*`). The rules live in Firewall / FirewallAdmin. */
final class FirewallController extends CrmController
{
    public function __construct(
        private readonly FirewallAdmin $admin,
        private readonly Firewall $firewall,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = ['action' => (string) $request->query('action', ''), 'rule' => (string) $request->query('rule', ''), 'ip' => mb_substr(trim((string) $request->query('ip', '')), 0, 45)];
        $page = max(1, min((int) $request->query('page', '1'), 1000));
        $events = $this->admin->events($filters, $page);

        return $this->private(view_response('crm.admin.security.firewall', [
            'counts' => $this->admin->counts(24), 'topRules' => $this->admin->topRules(24), 'topIps' => $this->admin->topAddresses(24), 'hourly' => $this->admin->hourly(),
            'events' => $events['rows'], 'total' => $events['total'], 'page' => $page, 'filters' => $filters,
            'settings' => $this->admin->settings(), 'canManage' => can('security.manage'), 'yourIp' => $request->ip(),
        ]));
    }

    public function settings(Request $request): Response
    {
        return $this->private(view_response('crm.admin.security.firewall-settings', [
            'settings' => $this->admin->settings(), 'sets' => Signatures::SETS, 'countries' => $this->admin->countries(),
            'canManage' => can('security.manage'), 'yourIp' => $request->ip(), 'yourCountry' => $this->firewall->view($request)['country'],
        ]));
    }

    public function saveSettings(Request $request): Response
    {
        try {
            $this->admin->saveSettings($request->only(['enabled', 'monitor', 'modes', 'escalate_threshold', 'escalate_minutes', 'probe_ban']), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['escalate_threshold', 'escalate_minutes', 'probe_ban']), '/admin/security/firewall/settings');
        }
        flash('status', 'Firewall settings saved. They apply from the next request.');

        return Response::redirect('/admin/security/firewall/settings');
    }

    public function saveGeo(Request $request): Response
    {
        $codes = $request->input('countries', []);
        try {
            $this->admin->saveGeo((string) $request->input('geo_scope', 'off'), (string) $request->input('geo_mode', 'block'), is_array($codes) ? array_values($codes) : [], $this->firewall->view($request)['country'], $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/admin/security/firewall/settings');
        }
        flash('status', 'Country rules saved.');

        return Response::redirect('/admin/security/firewall/settings');
    }

    public function lockdown(Request $request): Response
    {
        try {
            $this->admin->setLockdown((string) $request->input('on', '') === '1', $request->ip(), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/admin/security/firewall/settings');
        }
        flash('status', (string) $request->input('on', '') === '1' ? 'Lockdown is on: only allow-listed addresses can reach sign-in and the staff area.' : 'Lockdown is off.');

        return Response::redirect('/admin/security/firewall/settings');
    }

    // ---- custom rules -------------------------------------------------------------------------------------------

    public function rules(Request $request): Response
    {
        $edit = (int) $request->query('edit', '0');

        return $this->private(view_response('crm.admin.security.firewall-rules', [
            'rules' => $this->admin->rules(), 'edit' => $edit > 0 ? $this->admin->rule($edit) : null,
            'parts' => FirewallAdmin::PARTS, 'operators' => FirewallAdmin::OPERATORS, 'actions' => FirewallAdmin::ACTIONS, 'canManage' => can('security.manage'),
        ]));
    }

    public function storeRule(Request $request): Response
    {
        return $this->saveRule($request, null);
    }

    public function updateRule(Request $request, string $id): Response
    {
        return $this->saveRule($request, $this->id($id));
    }

    public function toggleRule(string $id): Response
    {
        $this->admin->toggleRule($this->id($id), $this->currentUser()) || abort(404);
        flash('status', 'Rule switched.');

        return Response::redirect('/admin/security/firewall/rules');
    }

    public function deleteRule(string $id): Response
    {
        $this->admin->deleteRule($this->id($id), $this->currentUser()) || abort(404);
        flash('status', 'Rule removed.');

        return Response::redirect('/admin/security/firewall/rules');
    }

    // ---- tester -------------------------------------------------------------------------------------------------

    public function tester(Request $request): Response
    {
        $input = $request->only(['method', 'url', 'body', 'user_agent', 'ip', 'country']);
        $result = $input === [] || ($input['url'] ?? '') === '' ? null : $this->admin->test($input);

        return $this->private(view_response('crm.admin.security.firewall-test', ['input' => $input, 'result' => $result]));
    }

    // ---- internals ----------------------------------------------------------------------------------------------

    private function saveRule(Request $request, ?int $id): Response
    {
        $fields = ['name', 'part', 'header_name', 'operator', 'value', 'negate', 'action', 'priority', 'is_active', 'expires_in_hours', 'note'];
        try {
            $this->admin->saveRule($request->only($fields), $this->currentUser(), $id);
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only($fields), '/admin/security/firewall/rules' . ($id !== null ? '?edit=' . $id : ''));
        } catch (DomainRuleException) {
            abort(404);
        }
        flash('status', $id === null ? 'Rule added. It applies from the next request.' : 'Rule saved.');

        return Response::redirect('/admin/security/firewall/rules');
    }

    private function id(string $raw): int
    {
        return preg_match('/^\d{1,18}$/D', $raw) === 1 ? (int) $raw : abort(404);
    }

    private function private(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store, private');
    }
}
