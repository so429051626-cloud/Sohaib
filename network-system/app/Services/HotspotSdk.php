<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Settings;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;

/**
 * ملفات ربط صفحة الهوتسبوت بالنظام، ونشرها على أجهزة MikroTik، وسكربت تجهيز جهاز MikroTik.
 */
final class HotspotSdk
{
    public const SDK_FILE = 'sohaibnet.js';
    public const CONFIG_FILE = 'sn-config.js';

    public static function panelHost(): string
    {
        return (string) parse_url(Settings::panelUrl(), PHP_URL_HOST);
    }

    /** محتوى ملف الإعداد الخاص بكل جهاز MikroTik */
    public static function configJs(array $router): string
    {
        $cfg = [
            'panel'    => Settings::panelUrl(),
            'key'      => $router['router_key'],
            'timeout'  => max(2, Settings::int('hotspot.timeout', 8)),
            'fallback' => Settings::bool('hotspot.fallback'),
            'codeCase' => (string) Settings::get('hotspot.code_case', 'none'),
            'router'   => $router['name'],
        ];
        return "/* إعداد ربط الهوتسبوت — " . $router['name'] . " — " . date('Y-m-d H:i') . " */\n"
            . 'window.SN_CONFIG = ' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
    }

    public static function sdkPath(): string
    {
        return PUBLIC_PATH . '/hotspot/sdk/' . self::SDK_FILE;
    }

    /**
     * نشر الملفين على مجلدات الهوتسبوت في جهاز MikroTik وضبط Walled Garden.
     * @return array{ok:bool, message:string}
     */
    public static function deploy(array $router): array
    {
        $panel = Settings::panelUrl();
        if ($panel === '' || !preg_match('#^https?://#', $panel)) {
            return ['ok' => false, 'message' => 'اضبط الرابط العام للنظام في الإعدادات أولاً.'];
        }
        try {
            $gw = Gateway::for($router, 10.0);
            $dirs = $gw->hotspotHtmlDirectories();
            if (!$dirs) {
                $dirs = [$router['hotspot_dir'] ?: 'hotspot'];
            }
            $sdkUrl = $panel . '/hotspot/sdk/' . self::SDK_FILE . '?v=' . filemtime(self::sdkPath());
            $cfgUrl = $panel . '/hotspot/sdk/config/' . $router['router_key'] . '.js';
            foreach ($dirs as $dir) {
                $gw->fetch($sdkUrl, rtrim($dir, '/') . '/' . self::SDK_FILE);
                $gw->fetch($cfgUrl, rtrim($dir, '/') . '/' . self::CONFIG_FILE);
            }
            self::ensureWalledGarden($gw);
            DB::update('routers', ['hotspot_dir' => $dirs[0]], 'id = ?', [$router['id']]);
            $gw->close();
            Audit::log('hotspot.deploy', 'نشر ملفات ربط الهوتسبوت على ' . $router['name'], 'router', (int) $router['id'], ['dirs' => $dirs]);
            return ['ok' => true, 'message' => 'تم النشر في: ' . implode('، ', $dirs)];
        } catch (MikroTikException $e) {
            return ['ok' => false, 'message' => $e->friendly()];
        }
    }

    /** السماح بالوصول إلى النظام قبل تسجيل الدخول */
    public static function ensureWalledGarden(Gateway $gw): void
    {
        $host = self::panelHost();
        if ($host === '') {
            return;
        }
        $c = $gw->client();
        $exists = false;
        foreach ($c->print('/ip/hotspot/walled-garden', [], ['.id', 'dst-host', 'comment']) as $r) {
            if (($r['dst-host'] ?? '') === $host) {
                $exists = true;
            }
        }
        if (!$exists) {
            $c->add('/ip/hotspot/walled-garden', ['dst-host' => $host, 'action' => 'allow', 'comment' => 'sn:panel']);
        }
        $exists = false;
        foreach ($c->print('/ip/hotspot/walled-garden/ip', [], ['.id', 'dst-host', 'comment']) as $r) {
            if (($r['dst-host'] ?? '') === $host) {
                $exists = true;
            }
        }
        if (!$exists) {
            $c->add('/ip/hotspot/walled-garden/ip', ['dst-host' => $host, 'action' => 'accept', 'comment' => 'sn:panel']);
        }
    }

    /** سكربت تجهيز جهاز MikroTik (يُلصق في Terminal) */
    public static function setupScript(array $router, bool $withPassword): string
    {
        $host = self::panelHost() ?: 'panel.example.com';
        $user = $router['api_username'];
        $pass = '';
        if ($withPassword) {
            try {
                $pass = Crypto::decrypt((string) $router['api_password']);
            } catch (\Throwable $e) {
                $pass = '';
            }
        }
        $passArg = $pass !== '' && !preg_match('/["\\\\$?]/', $pass) ? $pass : 'CHANGE-ME';
        $port = (int) $router['api_port'];
        $service = $router['api_ssl'] ? 'api-ssl' : 'api';
        $lines = [
            '# ' . Settings::networkName() . ' — تجهيز جهاز MikroTik: ' . $router['name'],
            '# 1) مستخدم واجهة API للنظام',
            '/user group add name=sn-api policy=read,write,api,test,reboot,ftp,sensitive,policy comment="SN panel"',
            '/user add name=' . $user . ' group=sn-api password="' . $passArg . '" comment="SN panel"',
            '# 2) تفعيل خدمة API على المنفذ ' . $port,
            '/ip service set ' . $service . ' disabled=no port=' . $port,
        ];
        if ($router['api_ssl']) {
            $lines[] = '/certificate add name=sn-api common-name=sn-api days-valid=3650 key-usage=digital-signature,key-encipherment,tls-server';
            $lines[] = '/certificate sign sn-api';
            $lines[] = ':delay 15s';
            $lines[] = '/ip service set api-ssl certificate=sn-api';
        }
        $lines[] = '# 3) السماح لصفحة الهوتسبوت بالوصول إلى النظام قبل الدخول';
        $lines[] = '/ip hotspot walled-garden add dst-host=' . $host . ' action=allow comment="sn:panel"';
        $lines[] = '/ip hotspot walled-garden ip add dst-host=' . $host . ' action=accept comment="sn:panel"';
        return implode("\n", $lines) . "\n";
    }
}
