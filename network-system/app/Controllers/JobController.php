<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\JobRunner;

final class JobController extends Controller
{
    private function find(int $id): array
    {
        $job = DB::row('SELECT j.*, e.name AS employee_name FROM jobs j LEFT JOIN employees e ON e.id = j.created_by WHERE j.id = ?', [$id]);
        if (!$job) {
            throw new HttpException(404);
        }
        if (!can('jobs.view') && (int) $job['created_by'] !== Auth::id()) {
            throw new HttpException(403);
        }
        return $job;
    }

    public function index(Request $request): Response
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM jobs');
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows('SELECT j.*, e.name AS employee_name FROM jobs j LEFT JOIN employees e ON e.id = j.created_by ORDER BY j.id DESC' . $pager->limitSql());
        return view('jobs/index', compact('rows', 'pager'));
    }

    public function show(Request $request, int $id): Response
    {
        $job = $this->find($id);
        return view('jobs/show', ['job' => $job, 'state' => json_decode((string) $job['state'], true) ?: []]);
    }

    /** تقدّم العملية خطوة (يُستدعى تكراراً من الصفحة) */
    public function step(Request $request, int $id): Response
    {
        $job = $this->find($id);
        Session::release();
        if (in_array($job['status'], ['pending', 'running'], true)) {
            $job = JobRunner::step($id, 8.0) ?? $job;
        }
        $state = json_decode((string) $job['state'], true) ?: [];
        return json_response([
            'ok'       => true,
            'status'   => $job['status'],
            'label'    => JobRunner::STATUS[$job['status']][0] ?? $job['status'],
            'progress' => (int) $job['progress'],
            'total'    => (int) $job['total'],
            'errors'   => (int) $job['errors'],
            'message'  => $job['message'],
            'log'      => array_slice($state['log'] ?? [], -30),
        ]);
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->find($id);
        JobRunner::cancel($id);
        return $this->ok('تم إلغاء العملية.', '/jobs/' . $id);
    }
}
