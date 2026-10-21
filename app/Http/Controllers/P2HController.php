<?php
namespace App\Http\Controllers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class P2HController extends Controller
{
    //
    public function index(Request $request)
    {
        try {
            $startDate = $request->filled('startDate')
                ? $request->input('startDate')
                : Carbon::now()->toDateString();
            $endDate = $request->filled('endDate')
                ? $request->input('endDate')
                : $startDate;
            $shiftP2H = $request->input('shiftP2H');
            $cluster = $request->input('cluster');
            $statusVerifikasi = $request->input(
                'statusVerifikasi',
                'Semua'
            );
            $searchValue = trim(
                (string) $request->input('search.value', '')
            );
            $validator = \Illuminate\Support\Facades\Validator::make(
                [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'shiftP2H' => $shiftP2H,
                    'cluster' => $cluster,
                    'statusVerifikasi' => $statusVerifikasi,
                ],
                [
                    'startDate' => 'required|date_format:Y-m-d',
                    'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
                    'shiftP2H' => 'nullable|in:6,7',
                    'cluster' => 'nullable|in:EX,HD,MG,BD',
                    'statusVerifikasi' => [
                        'nullable',
                        \Illuminate\Validation\Rule::in([
                            'Semua',
                            'Sudah Diverifikasi',
                            'Belum Diverifikasi',
                        ]),
                    ],
                ]
            );
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parameter filter tidak valid.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Silakan login kembali.',
                ], 401);
            }
            $userRoleList = [
                'FOREMAN',
                'PJS FOREMAN',
                'SUPERVISOR',
                'PJS SUPERVISOR',
            ];
            $role = strtoupper(
                trim((string) ($user->role ?? ''))
            );
            $isForeman = in_array(
                $role,
                $userRoleList,
                true
            ) && (int) $user->departemen_id === 11;
            $userSection = $isForeman
                ? strtoupper(trim((string) ($user->section ?? '')))
                : null;
            $query = DB::connection('p2h')
                ->table('opr_oprchecklist as p')
                ->where('p.vhc_id', 'like', 'FT%')
                ->select([
                    'p.uuid',
                    'p.vhc_id',
                    'p.opr_reporttime',
                    'p.opr_nrp',
                    'p.opr_shiftno',
                    'p.opr_shiftdate',
                    'p.mtr_hourmeter',
                    'p.verified_operator',
                    'p.dateverified_operator',
                    'p.verified_mekanik',
                    'p.dateverified_mekanik',
                    'p.verified_foreman',
                    'p.dateverified_foreman',
                    'p.verified_supervisor',
                    'p.dateverified_supervisor',
                ])
                ->selectSub(function ($q) {
                    $q->from('opr_oprchecklistitem as ci')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('ci.vhc_id', 'p.vhc_id')
                        ->whereColumn(
                            'ci.opr_reporttime',
                            'p.opr_reporttime'
                        )
                        ->where('ci.checklistval', 0);
                }, 'val_notok')
                ->where(
                    'p.opr_shiftdate',
                    '>=',
                    $startDate
                )
                ->where(
                    'p.opr_shiftdate',
                    '<',
                    Carbon::parse($endDate)
                        ->addDay()
                        ->toDateString()
                );
            if (!empty($shiftP2H)) {
                $query->where(
                    'p.opr_shiftno',
                    (int) $shiftP2H
                );
            }
            if (!empty($cluster)) {
                $query->where(
                    'p.vhc_id',
                    'like',
                    $cluster . '%'
                );
            }
            if ($searchValue !== '') {
                $query->where(function ($q) use ($searchValue) {
                    $q->where(
                        'p.opr_nrp',
                        'ilike',
                        '%' . $searchValue . '%'
                    )->orWhere(
                        'p.vhc_id',
                        'ilike',
                        '%' . $searchValue . '%'
                    );
                });
            }
            if ($statusVerifikasi === 'Sudah Diverifikasi') {
                $query->where(function ($q) {
                    $q->whereNotNull('p.verified_foreman')
                        ->orWhereNotNull('p.verified_supervisor');
                });
            }
            if ($statusVerifikasi === 'Belum Diverifikasi') {
                $query->where(function ($q) {
                    $q->whereNull('p.verified_foreman')
                        ->whereNull('p.verified_supervisor');
                });
            }
            if ($isForeman && $userSection) {
                if ($userSection === 'WHEEL') {
                    $query->where(function ($q) {
                        $q->where('p.vhc_id', 'like', 'MG%')
                            ->orWhere('p.vhc_id', 'like', 'HD%');
                    });
                }
                if ($userSection === 'TRACK EXCA') {
                    $query->where(
                        'p.vhc_id',
                        'like',
                        'EX%'
                    );
                }
                if ($userSection === 'TRACK DOZER') {
                    $query->where(
                        'p.vhc_id',
                        'like',
                        'BD%'
                    );
                }
            }
            $checklists = $query
                ->orderByDesc('val_notok')
                ->orderBy('p.vhc_id', 'asc')
                ->get();
            $nikList = $checklists
                ->flatMap(function ($row) {
                    $nrp = trim(
                        (string) ($row->opr_nrp ?? '')
                    );
                    if (str_ends_with($nrp, 'S1')) {
                        $nrp = substr($nrp, 0, -1);
                    }
                    return [
                        $nrp,
                        $row->verified_mekanik,
                        $row->verified_foreman,
                        $row->verified_supervisor,
                    ];
                })
                ->filter(function ($value) {
                    return $value !== null
                        && trim((string) $value) !== '';
                })
                ->map(function ($value) {
                    return trim((string) $value);
                })
                ->unique()
                ->values()
                ->all();
            $users = collect();
            foreach (array_chunk($nikList, 1000) as $nikChunk) {
                $rows = DB::connection('daily_foreman')
                    ->table('users')
                    ->select('nik', 'name')
                    ->whereIn('nik', $nikChunk)
                    ->get();
                foreach ($rows as $row) {
                    $users->put(
                        trim((string) $row->nik),
                        $row->name
                    );
                }
            }
            $formatDateTime = function ($value) {
                if (!$value) {
                    return null;
                }
                return Carbon::parse($value)
                    ->format('Y-m-d H:i:s');
            };
            $results = $checklists->map(function ($row) use (
                $users,
                $formatDateTime
            ) {
                $vhcId = trim(
                    (string) ($row->vhc_id ?? '')
                );
                $nrp = trim(
                    (string) ($row->opr_nrp ?? '')
                );
                if (str_ends_with($nrp, 'S1')) {
                    $nrp = substr($nrp, 0, -1);
                }
                $nikMekanik = $row->verified_mekanik;
                $nikForeman = $row->verified_foreman
                    ?? $row->verified_supervisor;
                $nikSupervisor = $row->verified_supervisor;
                $namaOperator = $users->get($nrp);
                $namaMekanik = $nikMekanik
                    ? $users->get(trim((string) $nikMekanik))
                    : null;
                $namaForeman = $nikForeman
                    ? $users->get(trim((string) $nikForeman))
                    : null;
                $namaSupervisor = $nikSupervisor
                    ? $users->get(trim((string) $nikSupervisor))
                    : null;
                // Foreman ATAU Supervisor cukup satu kali memverifikasi.
                $nikVerifier = trim((string) ($row->verified_foreman ?: $row->verified_supervisor ?: ''));
                $roleVerifier = !empty($row->verified_foreman) ? 'FOREMAN'
                    : (!empty($row->verified_supervisor) ? 'SUPERVISOR' : null);
                $atVerifier = !empty($row->verified_foreman)
                    ? $row->dateverified_foreman : $row->dateverified_supervisor;
                $sudahDiverifikasi = $nikVerifier !== '';

                return [
                    'SUDAH_DIVERIFIKASI' => $sudahDiverifikasi,
                    'STATUS_VERIFIKASI' => $sudahDiverifikasi ? 'Sudah Diverifikasi' : 'Belum Diverifikasi',
                    'VERIFIED_BY' => $sudahDiverifikasi ? $nikVerifier : null,
                    'VERIFIED_BY_NAME' => $sudahDiverifikasi ? $users->get($nikVerifier) : null,
                    'VERIFIED_BY_ROLE' => $roleVerifier,
                    'VERIFIED_AT' => $formatDateTime($atVerifier),
                    'UUID' => $row->uuid,
                    'VHC_ID' => $vhcId,
                    'VHC_PREFIX' => substr($vhcId, 0, 2),
                    'OPR_REPORTTIME' => $formatDateTime(
                        $row->opr_reporttime
                    ),
                    'OPR_NRP' => $nrp,
                    'PERSONALNAME' => $namaOperator,
                    'VAL_NOTOK' => (int) $row->val_notok,
                    'DATEVERIFIED_OPERATOR' => $formatDateTime(
                        $row->dateverified_operator
                    ),
                    'VERIFIED_OPERATOR' =>
                        $row->verified_operator,
                    'DATEVERIFIED_MEKANIK' => $formatDateTime(
                        $row->dateverified_mekanik
                    ),
                    'VERIFIED_MEKANIK' =>
                        $row->verified_mekanik,
                    'NAMAMEKANIK' => $namaMekanik,
                    'DATEVERIFIED_FOREMAN' => $formatDateTime(
                        $row->dateverified_foreman
                        ?? $row->dateverified_supervisor
                    ),
                    'VERIFIED_FOREMAN' => $nikForeman,
                    'NAMAFOREMAN' => $namaForeman,
                    'DATEVERIFIED_SUPERVISOR' => $formatDateTime(
                        $row->dateverified_supervisor
                    ),
                    'VERIFIED_SUPERVISOR' => $nikSupervisor,
                    'NAMASUPERVISOR' => $namaSupervisor,
                    'MTR_HOURMETER' =>
                        $row->mtr_hourmeter,
                    'OPR_SHIFTDATE' => $row->opr_shiftdate
                        ? Carbon::parse($row->opr_shiftdate)
                            ->format('Y-m-d')
                        : null,
                    'OPR_SHIFTNO' =>
                        $row->opr_shiftno,
                    'IS_FOREMAN_ROW' => false,
                    'SECTION_OK' => true,
                ];
            })->values();
            return response()->json([
                'status' => 'success',
                'data' => $results,
                'total' => $results->count(),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('P2H Mobile API Error', [
                'message' => $th->getMessage(),
            ]);
            $response = [
                'status' => 'error',
                'message' => 'Gagal mengambil data P2H.',
            ];
            if (config('app.debug')) {
                $response['error'] = $th->getMessage();
            }
            return response()->json($response, 500);
        }
    }
    public function detail(Request $request)
    {
        $validated = $request->validate([
            'VHC_ID' => 'required|string|max:40',
            'OPR_REPORTTIME' => 'required|date',
        ]);
        $vhcId = strtoupper(trim($validated['VHC_ID']));
        if (!str_starts_with($vhcId, 'FT')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Halaman P2H mobile hanya menampilkan unit FT.',
            ], 422);
        }
        try {
            $reportAt = Carbon::parse($validated['OPR_REPORTTIME'])->startOfSecond();
            $from = $reportAt->format('Y-m-d H:i:s');
            $until = $reportAt->copy()->addSecond()->format('Y-m-d H:i:s');
            $items = DB::connection('p2h')
                ->table('opr_oprchecklistitem')
                ->select(
                    'id', 'uuid', 'equ_typeid', 'checklistgroupid', 'checklistitemid',
                    'checklistnotes', 'checklistval', 'catatan_mekanik', 'kbj', 'jawaban',
                    'opr_reporttime', 'opr_shiftdate', 'opr_shiftno', 'vhc_id'
                )
                ->where('vhc_id', $vhcId)
                ->where('opr_reporttime', '>=', $from)
                ->where('opr_reporttime', '<', $until)
                ->orderBy('checklistgroupid')
                ->orderBy('checklistitemid')
                ->get();
            if ($items->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Detail checklist P2H tidak ditemukan.',
                ], 404);
            }
            $makeKey = static fn ($type, $group, $id) =>
                strtoupper(trim((string) $type)) . '|' .
                strtoupper(trim((string) $group)) . '|' .
                strtoupper(trim((string) $id));
            $master = DB::connection('focus')
                ->table('FLT_EQUCHECKLISTITEM')
                ->select('EQU_TYPEID', 'CHECKLISTGROUPID', 'CHECKLISTITEMID', 'CHECKLISTITEMDESCRIPTION')
                ->get()
                ->keyBy(fn ($m) => $makeKey($m->EQU_TYPEID, $m->CHECKLISTGROUPID, $m->CHECKLISTITEMID));
            $uniqueItems = $items->unique(fn ($item) =>
                $makeKey($item->equ_typeid, $item->checklistgroupid, $item->checklistitemid)
            )->values();
            $mappedItems = $uniqueItems->map(function ($item) use ($master, $makeKey) {
                $key = $makeKey($item->equ_typeid, $item->checklistgroupid, $item->checklistitemid);
                $description = $master->get($key)?->CHECKLISTITEMDESCRIPTION;
                return [
                    'ID' => $item->id,
                    'UUID' => $item->uuid,
                    'EQU_TYPEID' => strtoupper(trim((string) $item->equ_typeid)),
                    'CHECKLISTGROUPID' => strtoupper(trim((string) $item->checklistgroupid)),
                    'CHECKLISTITEMID' => $item->checklistitemid,
                    'CHECKLISTITEMDESCRIPTION' => $description ?? 'MASTER TIDAK DITEMUKAN (' . $key . ')',
                    'CHECKLISTVAL' => $item->checklistval,
                    'CHECKLISTNOTES' => $item->checklistnotes,
                    'CATATAN_MEKANIK' => $item->catatan_mekanik,
                    'KBJ' => $item->kbj,
                    'JAWABAN' => $item->jawaban,
                ];
            });
            $aaCount = $mappedItems->filter(fn ($item) =>
                (string) $item['CHECKLISTVAL'] === '0' &&
                in_array($item['CHECKLISTGROUPID'], ['A', 'AA'], true)
            )->count();
            $notOkCount = $mappedItems->filter(fn ($item) => (string) $item['CHECKLISTVAL'] === '0')->count();
            $first = $uniqueItems->first();
            $header = DB::connection('p2h')
                ->table('opr_oprchecklist')
                ->where('vhc_id', $vhcId)
                ->where('opr_shiftno', $first->opr_shiftno)
                ->where('opr_reporttime', '>=', $from)
                ->where('opr_reporttime', '<', $until)
                ->first();
            $verifiedMechanic = $header && $header->verified_mekanik !== null;

            // Verifikasi pengawas final oleh Foreman ATAU Supervisor.
            $verifiedForeman = trim((string) ($header?->verified_foreman ?? ''));
            $verifiedSupervisor = trim((string) ($header?->verified_supervisor ?? ''));
            $alreadyVerified = $verifiedForeman !== '' || $verifiedSupervisor !== '';
            $verifiedBy = $verifiedForeman !== '' ? $verifiedForeman : $verifiedSupervisor;
            $verifiedRole = $verifiedForeman !== '' ? 'FOREMAN'
                : ($verifiedSupervisor !== '' ? 'SUPERVISOR' : null);
            $verifiedAt = $verifiedForeman !== ''
                ? $header?->dateverified_foreman : $header?->dateverified_supervisor;
            $verifiedName = null;

            if ($verifiedBy !== '') {
                $verifiedName = DB::connection('daily_foreman')
                    ->table('users')
                    ->where('nik', $verifiedBy)
                    ->value('name');
            }

            $role = strtoupper(trim((string) (Auth::user()->role ?? '')));
            $department = (int) (Auth::user()->departemen_id ?? 0);
            $stage = null;
            $reason = null;
            if (!$header) {
                $reason = 'Header P2H belum tersedia. Verifikasi belum dapat dilakukan.';
            } elseif ($department === 11 && in_array($role, ['FOREMAN', 'SUPERVISOR', 'SUPERINTENDENT'], true)) {
                if ($aaCount > 0 && !$verifiedMechanic) {
                    $stage = 'MEKANIK';
                } else {
                    $reason = $verifiedMechanic ? 'Sudah diverifikasi mekanik.' : 'Tidak ada temuan A/AA yang memerlukan verifikasi mekanik.';
                }
            } elseif ($department === 7 && in_array($role, ['FOREMAN', 'SUPERVISOR'], true)) {
                if ($alreadyVerified) {
                    $reason = 'P2H sudah diverifikasi pengawas. Tidak diperlukan verifikasi ulang.';
                } elseif ($aaCount > 0 && !$verifiedMechanic) {
                    $reason = 'Temuan kode A/AA wajib diverifikasi mekanik terlebih dahulu.';
                } elseif ($role === 'FOREMAN' && $header->verified_foreman !== null) {
                    $reason = 'Sudah diverifikasi Foreman.';
                } elseif ($role === 'SUPERVISOR' && $header->verified_supervisor !== null) {
                    $reason = 'Sudah diverifikasi Supervisor.';
                } else {
                    $stage = $role;
                }
            } else {
                $reason = 'Anda tidak memiliki akses verifikasi.';
            }
            return response()->json([
                'status' => 'success',
                'data' => [
                    'VHC_ID' => $vhcId,
                    'OPR_REPORTTIME' => $from,
                    'OPR_SHIFTDATE' => $first->opr_shiftdate,
                    'OPR_SHIFTNO' => $first->opr_shiftno,
                    'MTR_HOURMETER' => $header?->mtr_hourmeter,
                    'VERIFIED_OPERATOR' => $header?->verified_operator,
                    'VERIFIED_MEKANIK' => $header?->verified_mekanik,
                    'VERIFIED_FOREMAN' => $header?->verified_foreman,
                    'VERIFIED_SUPERVISOR' => $header?->verified_supervisor,
                    'SUDAH_DIVERIFIKASI' => $alreadyVerified,
                    'STATUS_VERIFIKASI' => $alreadyVerified ? 'Sudah Diverifikasi' : 'Belum Diverifikasi',
                    'VERIFIED_BY' => $verifiedBy !== '' ? $verifiedBy : null,
                    'VERIFIED_BY_NAME' => $verifiedName,
                    'VERIFIED_BY_ROLE' => $verifiedRole,
                    'VERIFIED_AT' => $verifiedAt ? Carbon::parse($verifiedAt)->format('Y-m-d H:i:s') : null,
                    'DATEVERIFIED_FOREMAN' => $header?->dateverified_foreman
                        ? Carbon::parse($header->dateverified_foreman)->format('Y-m-d H:i:s') : null,
                    'DATEVERIFIED_SUPERVISOR' => $header?->dateverified_supervisor
                        ? Carbon::parse($header->dateverified_supervisor)->format('Y-m-d H:i:s') : null,
                    'JUMLAH_AA_TERISI' => $aaCount,
                    'JUMLAH_NOT_OK' => $notOkCount,
                    'VERIFIKASI_MEKANIK' => (bool) $verifiedMechanic,
                    'CAN_VERIFY' => $stage !== null,
                    'VERIFY_STAGE' => $stage,
                    'VERIFY_LABEL' => $stage === 'MEKANIK' ? 'Mekanik'
                        : ($stage !== null ? ucfirst(strtolower($stage)) . ' Planning' : null),
                    'BLOCK_REASON' => $reason,
                    'ITEMS' => $mappedItems->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('P2H mobile detail', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            return response()->json([
                'status' => 'error', 'message' => 'Gagal mengambil detail P2H.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    public function verifikasi(Request $request)
    {
        $validated = $request->validate([
            'VHC_ID' => 'required|string|max:40',
            'OPR_REPORTTIME' => 'required|date',
            'OPR_NRP' => 'required|string|max:50',
            'items' => 'sometimes|array|max:500',
            'items.*.ID' => 'required|integer',
            'items.*.CATATAN_MEKANIK' => 'nullable|string|max:2000',
            'items.*.KBJ' => 'nullable|string|max:255',
            'items.*.JAWABAN' => 'nullable|string|max:2000',
        ]);
        $user = Auth::user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Silakan login kembali.'], 401);
        }
        $vhcId = strtoupper(trim($validated['VHC_ID']));
        if (!str_starts_with($vhcId, 'FT')) {
            return response()->json(['status' => 'error', 'message' => 'Hanya unit FT yang didukung.'], 422);
        }
        $role = strtoupper(trim((string) ($user->role ?? '')));
        $department = (int) ($user->departemen_id ?? 0);
        $isMechanic = $department === 11 && in_array($role, ['FOREMAN', 'SUPERVISOR', 'SUPERINTENDENT'], true);
        $isPlanning = $department === 7 && in_array($role, ['FOREMAN', 'SUPERVISOR'], true);
        if (!$isMechanic && !$isPlanning) {
            return response()->json(['status' => 'error', 'message' => 'Anda tidak memiliki akses verifikasi.'], 403);
        }
        $reportAt = Carbon::parse($validated['OPR_REPORTTIME'])->startOfSecond();
        $from = $reportAt->format('Y-m-d H:i:s');
        $until = $reportAt->copy()->addSecond()->format('Y-m-d H:i:s');
        try {
            return DB::connection('p2h')->transaction(function () use (
                $validated, $user, $vhcId, $role, $isMechanic, $from, $until
            ) {
                $connection = DB::connection('p2h');
                $items = $connection->table('opr_oprchecklistitem')
                    ->where('vhc_id', $vhcId)
                    ->where('opr_reporttime', '>=', $from)
                    ->where('opr_reporttime', '<', $until)
                    ->get();
                if ($items->isEmpty()) {
                    return response()->json(['status' => 'error', 'message' => 'Checklist tidak ditemukan.'], 404);
                }
                $first = $items->first();
                $headerQuery = $connection->table('opr_oprchecklist')
                    ->where('vhc_id', $vhcId)
                    ->where('opr_shiftno', $first->opr_shiftno)
                    ->where('opr_reporttime', '>=', $from)
                    ->where('opr_reporttime', '<', $until);
                $header = (clone $headerQuery)->lockForUpdate()->first();
                if (!$header) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Header P2H belum ada. Periksa sinkronisasi data P2H terlebih dahulu.',
                    ], 409);
                }
                $aaCount = $items->filter(fn ($item) =>
                    (string) $item->checklistval === '0' &&
                    in_array(strtoupper(trim((string) $item->checklistgroupid)), ['A', 'AA'], true)
                )->unique(fn ($item) =>
                    strtoupper(trim((string) $item->equ_typeid)) . '|' .
                    strtoupper(trim((string) $item->checklistgroupid)) . '|' .
                    strtoupper(trim((string) $item->checklistitemid))
                )->count();
                if ($isMechanic) {
                    if ($aaCount === 0 || $header->verified_mekanik !== null) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Verifikasi mekanik tidak diperlukan atau sudah dilakukan.',
                        ], 409);
                    }
                    $verifyFields = [
                        'verified_mekanik' => $user->nik,
                        'dateverified_mekanik' => now(),
                    ];
                } else {
                    if ($aaCount > 0 && $header->verified_mekanik === null) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Temuan A/AA harus diverifikasi mekanik terlebih dahulu.',
                        ], 409);
                    }
                    $verifyFields = $role === 'FOREMAN'
                        ? ['verified_foreman' => $user->nik, 'dateverified_foreman' => now()]
                        : ['verified_supervisor' => $user->nik, 'dateverified_supervisor' => now()];
                    // Kunci header sudah diambil dengan lockForUpdate(): aman dari verifikasi bersamaan.
                    if (trim((string) ($header->verified_foreman ?? '')) !== '' ||
                        trim((string) ($header->verified_supervisor ?? '')) !== '') {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'P2H sudah diverifikasi Foreman atau Supervisor. Verifikasi ulang tidak diperlukan.',
                        ], 409);
                    }
                }
                // Catatan hanya boleh disunting oleh tahap yang berwenang.
                $byId = $items->keyBy(fn ($item) => (string) $item->id);
                foreach (($validated['items'] ?? []) as $entry) {
                    $item = $byId->get((string) $entry['ID']);
                    if (!$item) {
                        return response()->json([
                            'status' => 'error', 'message' => 'Item checklist tidak sesuai dengan unit dan waktu laporan.',
                        ], 422);
                    }
                    $changes = [];
                    if ($isMechanic && (string) $item->checklistval === '0' &&
                        array_key_exists('CATATAN_MEKANIK', $entry)) {
                        $changes['catatan_mekanik'] = $entry['CATATAN_MEKANIK'];
                    }
                    if (!$isMechanic) {
                        if (array_key_exists('KBJ', $entry)) $changes['kbj'] = $entry['KBJ'];
                        if (array_key_exists('JAWABAN', $entry)) $changes['jawaban'] = $entry['JAWABAN'];
                    }
                    if ($changes !== []) {
                        $connection->table('opr_oprchecklistitem')
                            ->where('id', $item->id)
                            ->where('vhc_id', $vhcId)
                            ->where('opr_reporttime', '>=', $from)
                            ->where('opr_reporttime', '<', $until)
                            ->update($changes);
                    }
                }
                // Semua pembaruan ada pada koneksi/transaction p2h yang SAMA.
                (clone $headerQuery)->update($verifyFields);
                $connection->table('opr_oprchecklistitem')
                    ->where('vhc_id', $vhcId)
                    ->where('opr_reporttime', '>=', $from)
                    ->where('opr_reporttime', '<', $until)
                    ->update([
                        'sys_updatedby' => $user->nik,
                        'sys_updatedat' => now(),
                    ]);
                return response()->json([
                    'status' => 'success',
                    'message' => 'P2H berhasil diverifikasi.',
                    'data' => ['VHC_ID' => $vhcId, 'OPR_REPORTTIME' => $from],
                ], 200);
            }, 3);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('P2H mobile verifikasi', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            return response()->json([
                'status' => 'error', 'message' => 'Gagal memverifikasi P2H.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
