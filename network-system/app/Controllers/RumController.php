<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * استقبال قياس سرعة الصفحات من أجهزة المستخدمين (partials/rum.php) وكتابته سطراً في storage/logs/rum.log،
 * ليقرأه أمر «madix speed» على الخادم. بلا قاعدة بيانات ولا جلسة: كتابة سطر في ملف فقط، والملف لا يتجاوز حجماً محدداً.
 */
final class RumController extends Controller
{
    /** حجم الملف قبل تدويره إلى rum.log.1 (يبقى ملفان على الأكثر) */
    private const MAX_FILE = 4 * 1024 * 1024;

    /** الحقول الرقمية المقبولة (ميلي ثانية أو عدد) */
    private const NUMBERS = ['s', 'red', 'pre', 'dns', 'con', 'tls', 'ttfb', 'dl', 'end', 'di', 'dcl', 'ld', 'fcp', 'lcp', 'rb', 'lt', 'ltn', 'inp', 'tap', 'kb', 'rn', 'rkb', 'sr', 'rtt', 'dn', 'mem', 'cpu', 'm', 'early', 'rf'];

    /** الحقول النصية المقبولة وشكلها */
    private const TEXTS = [
        'a'   => '/^(admin|net|login|landing)$/',
        'p'   => '#^/[A-Za-z0-9_\-/{}.]{0,100}$#',
        't'   => '/^(navigate|reload|back_forward|prerender)$/',
        'dt'  => '/^[a-z\-]{0,30}$/',
        'h'   => '#^[a-z0-9./]{0,12}$#',
        'net' => '/^[a-z0-9\-]{0,8}$/',
        'sn'  => '#^/[A-Za-z0-9_\-/.]{0,80}$#',
    ];

    public function store(Request $request): Response
    {
        $raw = $request->raw();
        $d = strlen($raw) <= 4096 ? json_decode($raw, true) : null;
        if (!is_array($d)) {
            return new Response('', 204);
        }
        $out = ['ts' => time()];
        foreach (self::TEXTS as $k => $re) {
            if (isset($d[$k]) && is_string($d[$k]) && preg_match($re, $d[$k])) {
                $out[$k] = $d[$k];
            }
        }
        if (!isset($out['a'], $out['p'])) {
            return new Response('', 204);
        }
        foreach (self::NUMBERS as $k) {
            if (isset($d[$k]) && is_numeric($d[$k])) {
                $out[$k] = (int) max(-1, min(600000, round((float) $d[$k])));
            }
        }
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/rum.log';
        clearstatcache(true, $file);
        if (@filesize($file) > self::MAX_FILE) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        return new Response('', 204);
    }
}
