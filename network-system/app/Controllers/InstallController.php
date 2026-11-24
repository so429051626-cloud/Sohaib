<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Installer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use Throwable;

final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        $done = Session::pull('_install_done');
        return view('install/index', [
            'requirements' => Installer::requirements(),
            'canInstall'   => Installer::requirementsMet(),
            'defaultUrl'   => $request->origin() . base_path(),
            'done'         => $done,
        ], 'auth');
    }

    /** بعد اكتمال التثبيت (ملف الإعداد أصبح موجوداً) */
    public function done(Request $request): Response
    {
        $done = Session::pull('_install_done');
        if (!$done) {
            return redirect('/login');
        }
        return view('install/index', [
            'requirements' => [],
            'canInstall'   => false,
            'defaultUrl'   => '',
            'done'         => $done,
        ], 'auth');
    }

    public function install(Request $request): Response
    {
        if (!Installer::requirementsMet()) {
            flash('error', 'متطلبات التشغيل غير مكتملة.');
            return redirect('/install');
        }
        $v = Validator::make($request->all(), [
            'app_url'        => 'required|max:255|regex:#^https?://#i',
            'network_name'   => 'required|max:100',
            'timezone'       => 'required|max:64',
            'db_host'        => 'required|max:255',
            'db_port'        => 'required|int|between:1,65535',
            'db_name'        => 'required|max:64',
            'db_user'        => 'required|max:64',
            'admin_name'     => 'required|max:120',
            'admin_username' => 'required|username|min:3|max:60',
            'admin_password' => 'required|min:8|max:100|confirmed',
        ], [
            'app_url' => 'رابط النظام', 'network_name' => 'اسم الشبكة', 'timezone' => 'المنطقة الزمنية',
            'db_host' => 'خادم قاعدة البيانات', 'db_port' => 'المنفذ', 'db_name' => 'اسم قاعدة البيانات',
            'db_user' => 'مستخدم قاعدة البيانات', 'admin_name' => 'اسم المدير',
            'admin_username' => 'اسم دخول المدير', 'admin_password' => 'كلمة المرور',
        ]);
        if (!in_array($request->str('timezone'), timezone_identifiers_list(), true)) {
            $v->addError('timezone', 'المنطقة الزمنية غير صالحة.');
        }
        if ($v->fails()) {
            Session::flashInput($request->all(), $v->errors());
            flash('error', (string) $v->first());
            return redirect('/install');
        }
        $data = $v->validated();
        $data['db_pass'] = (string) $request->input('db_pass', '');

        try {
            $result = Installer::install($data);
        } catch (Throwable $e) {
            Session::flashInput($request->all());
            $msg = $e instanceof \PDOException
                ? 'تعذّر الاتصال بقاعدة البيانات أو إنشاء الجداول: ' . $e->getMessage()
                : $e->getMessage();
            flash('error', $msg);
            return redirect('/install');
        }

        Session::set('_install_done', [
            'written' => $result['written'],
            'content' => $result['written'] ? null : Installer::configContent($result['config']),
            'cron_key' => $result['config']['app']['cron_key'],
            'url' => $result['config']['app']['url'],
        ]);
        return redirect('/install');
    }
}
