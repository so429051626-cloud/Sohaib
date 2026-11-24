<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim(latin_digits($request->str('q')));
        $results = ['cards' => [], 'subscribers' => [], 'devices' => [], 'routers' => [], 'units' => [], 'sites' => []];
        if ($q !== '') {
            $like = DB::like($q);
            if (can('cards.view') || can('check.use')) {
                $params = [$q, $q, $q, $like];
                $sql = 'SELECT c.id, c.username, c.short_code, c.status, c.is_disabled, cat.name AS category_name
                        FROM cards c JOIN categories cat ON cat.id = c.category_id
                        WHERE c.username = ? OR c.short_code = ? OR c.ext_serial = ? OR c.username LIKE ?';
                if (preg_match('/^#?(\d+)$/', $q, $m)) {
                    $sql .= ' OR c.id = ?';
                    $params[] = (int) $m[1];
                }
                $results['cards'] = DB::rows($sql . ' ORDER BY c.id DESC LIMIT 20', $params);
                // الإرسال المباشر عند تطابق واحد تام
                if (count($results['cards']) === 1 && in_array($q, [$results['cards'][0]['username'], $results['cards'][0]['short_code']], true) && can('check.use')) {
                    return redirect('/check/card/' . $results['cards'][0]['id']);
                }
            }
            if (can('subscribers.view')) {
                $results['subscribers'] = DB::rows(
                    'SELECT s.id, s.full_name, s.username, s.phone, s.expires_at, s.status, p.name AS plan_name
                     FROM subscribers s JOIN plans p ON p.id = s.plan_id
                     WHERE s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ? OR s.phone2 LIKE ? OR s.national_id = ?
                     ORDER BY s.full_name LIMIT 20',
                    [$like, $like, $like, $like, $q]
                );
            }
            if (can('devices.view') || can('sessions.view')) {
                $mac = normalize_mac($q);
                $results['devices'] = $mac
                    ? DB::rows('SELECT * FROM devices WHERE mac = ? LIMIT 20', [$mac])
                    : DB::rows('SELECT * FROM devices WHERE hostname LIKE ? OR last_ip = ? ORDER BY last_seen DESC LIMIT 20', [$like, $q]);
            }
            if (can('routers.view')) {
                $results['routers'] = DB::rows('SELECT id, name, host, status FROM routers WHERE name LIKE ? OR host LIKE ? OR identity LIKE ? LIMIT 10', [$like, $like, $like]);
            }
            if (can('inventory.view')) {
                $mac = normalize_mac($q);
                $results['units'] = DB::rows(
                    'SELECT u.id, u.serial, u.mac, u.status, i.name AS item_name FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id
                     WHERE u.serial LIKE ?' . ($mac ? ' OR u.mac = ?' : '') . ' ORDER BY u.id DESC LIMIT 10',
                    $mac ? [$like, $mac] : [$like]
                );
            }
            if (can('map.view')) {
                $results['sites'] = DB::rows('SELECT id, name, type, address FROM sites WHERE name LIKE ? OR address LIKE ? ORDER BY name LIMIT 10', [$like, $like]);
            }
        }
        return view('search/index', compact('q', 'results'));
    }
}
