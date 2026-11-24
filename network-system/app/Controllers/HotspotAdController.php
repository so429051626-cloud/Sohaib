<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\HotspotAds;

/**
 * إعلانات صفحة الهوتسبوت: صاحب الشبكة يضع عروضه وإعلانات محلات المنطقة على صفحة الدخول.
 */
final class HotspotAdController extends Controller
{
    public function index(Request $request): Response
    {
        $ads = DB::rows('SELECT * FROM hotspot_ads ORDER BY sort, id DESC');
        return view('hotspot/ads', [
            'ads' => $ads,
            'routers' => DB::pairs('SELECT id, name FROM routers ORDER BY name'),
            'interval' => HotspotAds::interval(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $ad = DB::row('SELECT * FROM hotspot_ads WHERE id = ?', [$id]);
        $this->notFoundUnless($ad);
        return $this->form($ad);
    }

    private function form(?array $ad): Response
    {
        return view('hotspot/ad-form', ['ad' => $ad, 'routers' => DB::pairs('SELECT id, name FROM routers ORDER BY name')]);
    }

    private function data(Request $request, ?array $ad): array
    {
        $d = $this->validate($request, [
            'title'      => 'required|max:120',
            'body'       => 'nullable|max:500',
            'advertiser' => 'nullable|max:120',
            'link'       => 'nullable|max:255',
            'phone'      => 'nullable|max:30',
            'starts_on'  => 'nullable|date',
            'ends_on'    => 'nullable|date',
            'sort'       => 'nullable|int|between:-1000,1000',
        ], [
            'title' => 'العنوان', 'body' => 'النص', 'advertiser' => 'المعلن', 'link' => 'الرابط', 'phone' => 'رقم التواصل',
            'starts_on' => 'تاريخ البداية', 'ends_on' => 'تاريخ النهاية', 'sort' => 'الترتيب',
        ], static function ($v) use ($request) {
            $link = trim($request->str('link'));
            if ($link !== '' && HotspotAds::safeLink($link) === null) {
                $v->addError('link', 'الرابط يبدأ بـ https:// أو http://');
            }
            $phone = trim($request->str('phone'));
            if ($phone !== '' && HotspotAds::telNumber($phone) === null) {
                $v->addError('phone', 'رقم التواصل غير صالح.');
            }
            $from = $request->str('starts_on');
            $to = $request->str('ends_on');
            if ($from !== '' && $to !== '' && $to < $from) {
                $v->addError('ends_on', 'تاريخ النهاية قبل تاريخ البداية.');
            }
            if ($request->str('router_scope') === 'selected' && !$request->ids('routers')) {
                $v->addError('routers', 'اختر جهاز MikroTik واحداً على الأقل، أو اختر «كل أجهزة MikroTik».');
            }
        });
        $routers = '';
        if ($request->str('router_scope') === 'selected') {
            $ids = $request->ids('routers');
            $valid = array_map('intval', DB::column('SELECT id FROM routers WHERE id IN (' . DB::in($ids) . ')', $ids));
            sort($valid);
            $routers = implode(',', $valid);
        }
        $blank = static fn ($v) => $v === null || trim((string) $v) === '' ? null : trim((string) $v);
        return [
            'title'      => trim((string) $d['title']),
            'body'       => $blank($d['body']),
            'advertiser' => $blank($d['advertiser']),
            'link'       => $blank($d['link']),
            'phone'      => $blank($d['phone']),
            'routers'    => $routers !== '' ? $routers : null,
            'starts_on'  => $blank($d['starts_on']),
            'ends_on'    => $blank($d['ends_on']),
            'is_active'  => $request->bool('is_active') ? 1 : 0,
            'sort'       => (int) ($d['sort'] ?? 0),
        ];
    }

    /** الصورة المرفوعة: null بلا تغيير، '' للحذف، أو المسار الجديد. false عند خطأ */
    private function image(Request $request, ?array $ad): string|null|false
    {
        if ($request->uploadError('image') !== null) {
            return false;
        }
        $file = $request->file('image');
        if ($file) {
            return SettingsController::saveImage($file, 'ad', 2 * 1048576) ?? false;
        }
        return $ad && $request->bool('remove_image') ? '' : null;
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request, null);
        $image = $this->image($request, null);
        if ($image === false) {
            return $this->backWithErrors($request, ['image' => 'الصورة يجب أن تكون PNG أو JPG أو WEBP بحجم لا يتجاوز 2 ميغابايت.'], '/hotspot/ads/create');
        }
        $id = DB::insert('hotspot_ads', $data + ['image' => $image ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('hotspot.ads', 'إضافة إعلان على صفحة الهوتسبوت: ' . $data['title'], 'hotspot_ad', $id);
        return $this->ok('تمت إضافة الإعلان.', '/hotspot/ads');
    }

    public function update(Request $request, int $id): Response
    {
        $ad = DB::row('SELECT * FROM hotspot_ads WHERE id = ?', [$id]);
        $this->notFoundUnless($ad);
        $data = $this->data($request, $ad);
        $image = $this->image($request, $ad);
        if ($image === false) {
            return $this->backWithErrors($request, ['image' => 'الصورة يجب أن تكون PNG أو JPG أو WEBP بحجم لا يتجاوز 2 ميغابايت.'], '/hotspot/ads/' . $id . '/edit');
        }
        if ($image !== null) {
            HotspotAds::deleteImage($ad['image']);
            $data['image'] = $image !== '' ? $image : null;
        }
        DB::update('hotspot_ads', $data + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('hotspot.ads', 'تعديل إعلان صفحة الهوتسبوت: ' . $data['title'], 'hotspot_ad', $id);
        return $this->ok('تم حفظ الإعلان.', '/hotspot/ads');
    }

    public function toggle(Request $request, int $id): Response
    {
        $ad = DB::row('SELECT * FROM hotspot_ads WHERE id = ?', [$id]);
        $this->notFoundUnless($ad);
        $on = (int) $ad['is_active'] === 1 ? 0 : 1;
        DB::update('hotspot_ads', ['is_active' => $on, 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('hotspot.ads', ($on ? 'تشغيل' : 'إيقاف') . ' إعلان صفحة الهوتسبوت: ' . $ad['title'], 'hotspot_ad', $id);
        return $this->ok($on ? 'الإعلان يُعرض الآن.' : 'أُوقف الإعلان.', '/hotspot/ads');
    }

    public function destroy(Request $request, int $id): Response
    {
        $ad = DB::row('SELECT * FROM hotspot_ads WHERE id = ?', [$id]);
        $this->notFoundUnless($ad);
        DB::delete('hotspot_ads', 'id = ?', [$id]);
        HotspotAds::deleteImage($ad['image']);
        Audit::log('hotspot.ads', 'حذف إعلان صفحة الهوتسبوت: ' . $ad['title'], 'hotspot_ad', $id);
        return $this->ok('تم حذف الإعلان.', '/hotspot/ads');
    }

    /** مدة عرض كل إعلان قبل الانتقال للتالي */
    public function settings(Request $request): Response
    {
        $d = $this->validate($request, ['interval' => 'required|int|between:3,60'], ['interval' => 'مدة عرض الإعلان']);
        Settings::set('hotspot.ads_interval', (string) (int) $d['interval']);
        Audit::log('hotspot.ads', 'مدة عرض إعلانات صفحة الهوتسبوت: ' . (int) $d['interval'] . ' ثانية', 'settings');
        return $this->ok('تم الحفظ.', '/hotspot/ads');
    }
}
