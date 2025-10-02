<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\FirebaseService;

class KKHController extends Controller
{
    //
    public function index(Request $request)
    {
        try {
            // Ambil parameter filter dari request
            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            if (!$startDate || !$endDate) {
                // Default ke hari ini jika tidak ada input
                $startDate = Carbon::now()->toDateString();
                $endDate = $startDate;
            }
            $shift     = $request->input('shift');   // "Pagi", "Malam", atau null
            $name      = $request->input('name');    // Nik tertentu atau "Semua"
            $verifikasi      = $request->input('verifikasi');    // Verifikasi tertentu atau "Semua"


            $kkh = DB::connection('sims')->table('db_payroll.dbo.web_kkh as kkh')
                ->leftJoin('db_payroll.dbo.tbl_data_hr as hr', 'kkh.nik', '=', 'hr.nik')
                ->leftJoin('db_payroll.dbo.tbl_data_hr as hr2', 'kkh.nik_pengawas', '=', 'hr2.nik')
                ->leftJoin('db_payroll.dbo.tm_departemen as dp', 'hr.Id_Departemen', '=', 'dp.ID_Departemen')
                ->leftJoin('db_payroll.dbo.tm_perusahaan as pr', 'hr.ID_Perusahaan', '=', 'pr.ID_Perusahaan')
                ->leftJoin('db_payroll.dbo.tm_jabatan as jb', 'hr.ID_Jabatan', '=', 'jb.ID_Jabatan')
                ->select(
                    'kkh.id',
                    'kkh.tgl',
                    DB::raw("FORMAT(kkh.tgl_input, 'yyyy-MM-dd HH:mm') as TANGGAL_DIBUAT"),
                    'hr.Nik as NIK_PENGISI',
                    'hr.Nama as NAMA_PENGISI',
                    'kkh.shift_kkh as SHIFT',
                    'jb.Jabatan as JABATAN',
                    DB::raw("
                        CASE
                            WHEN kkh.jam_pulang IS NULL OR LTRIM(RTRIM(kkh.jam_pulang)) = '' THEN '-'
                            ELSE
                                RIGHT('0' + LEFT(kkh.jam_pulang, CHARINDEX(':', kkh.jam_pulang) - 1), 2)
                                + ':' +
                                RIGHT('0' + RIGHT(kkh.jam_pulang, LEN(kkh.jam_pulang) - CHARINDEX(':', kkh.jam_pulang)), 2)
                        END AS JAM_PULANG
                    "),
                    DB::raw("
                        CASE
                            WHEN kkh.jam_tidur IS NULL OR LTRIM(RTRIM(kkh.jam_tidur)) = '' THEN '-'
                            ELSE
                                RIGHT('0' + LEFT(kkh.jam_tidur, CHARINDEX(':', kkh.jam_tidur) - 1), 2)
                                + ':' +
                                RIGHT('0' + RIGHT(kkh.jam_tidur, LEN(kkh.jam_tidur) - CHARINDEX(':', kkh.jam_tidur)), 2)
                        END AS JAM_TIDUR
                    "),
                    DB::raw("
                        CASE
                            WHEN kkh.jam_bangun IS NULL OR LTRIM(RTRIM(kkh.jam_bangun)) = '' THEN '-'
                            ELSE
                                RIGHT('0' + LEFT(kkh.jam_bangun, CHARINDEX(':', kkh.jam_bangun) - 1), 2)
                                + ':' +
                                RIGHT('0' + RIGHT(kkh.jam_bangun, LEN(kkh.jam_bangun) - CHARINDEX(':', kkh.jam_bangun)), 2)
                        END AS JAM_BANGUN
                    "),
                    DB::raw("
                        STR(
                            ROUND(
                                CASE
                                    WHEN DATEDIFF(MINUTE, kkh.jam_tidur, kkh.jam_bangun) < 0 THEN
                                        DATEDIFF(MINUTE, kkh.jam_tidur, DATEADD(DAY, 1, kkh.jam_bangun)) / 60.0
                                    ELSE
                                        DATEDIFF(MINUTE, kkh.jam_tidur, kkh.jam_bangun) / 60.0
                                END, 1
                            ), 10, 1
                        ) AS TOTAL_TIDUR
                    "),
                    DB::raw("
                        CASE
                            WHEN kkh.jam_berangkat IS NULL OR LTRIM(RTRIM(kkh.jam_berangkat)) = '' THEN '-'
                            ELSE
                                RIGHT('0' + LEFT(kkh.jam_berangkat, CHARINDEX(':', kkh.jam_berangkat) - 1), 2)
                                + ':' +
                                RIGHT('0' + RIGHT(kkh.jam_berangkat, LEN(kkh.jam_berangkat) - CHARINDEX(':', kkh.jam_berangkat)), 2)
                        END AS JAM_BERANGKAT
                    "),
                    'kkh.fit_or as FIT_BEKERJA',
                    DB::raw('UPPER(kkh.keluhan) as KELUHAN'),
                    'kkh.masalah_pribadi as MASALAH_PRIBADI',
                    'kkh.ferivikasi_pengawas',
                    DB::raw("
                        CASE
                            WHEN kkh.ferivikasi_pengawas = 1 THEN CAST(1 AS BIT)
                            ELSE CAST(0 AS BIT)
                        END AS VERIFIKASI
                    "),
                    'kkh.nik_pengawas as NIK_PENGAWAS',
                    'hr2.Nama as NAMA_PENGAWAS'
                )
                ->where('dp.Departemen', 'Planning');

            // Filter tanggal
            if ($startDate && $endDate) {
                $kkh->whereBetween('kkh.tgl', [$startDate, $endDate]);
            }

            // Filter shift
            if (!empty($shift)) {
                $kkh->where('kkh.shift_kkh', $shift);
            }

            // Filter nama
            if (!empty($name) && $name !== 'Semua') {
                $kkh->where('kkh.Nik', $name);
            }

            // Filter verifikasi
            if (!empty($verifikasi) && $verifikasi !== 'Semua') {
                if($verifikasi == "Belum diverifikasi"){
                    $kkh->whereIn('kkh.ferivikasi_pengawas', [null, 0]);
                }else{
                    $kkh->whereIn('kkh.ferivikasi_pengawas', [1]);
                }

            }

            $totalRecords = $kkh->count();

            $data = $kkh->orderBy('kkh.tgl')
            ->get();

            // Misal ambil role user dari token/login
            $userRole = strtoupper(Auth::user()->role ?? '');

            // Loop untuk tambahkan rule verifikasi
            $data->transform(function ($row) use ($userRole) {
                $jabatanPengawas = strtoupper($row->JABATAN ?? '');
                $isFuelMan = in_array($jabatanPengawas, ['FUELMAN', 'OPERATOR']);
                $allowedToVerify = false;

                // Cegah verifikasi diri sendiri
                if ($jabatanPengawas !== $userRole && !$row->ferivikasi_pengawas) {
                    if ($isFuelMan) {
                        $allowedToVerify = in_array($userRole, [
                            'JUNIOR FOREMAN', 'FOREMAN', 'JUNIOR STAFF', 'STAFF',
                            'SUPERVISOR', 'PJS. SUPERINTENDENT', 'SUPERINTENDENT'
                        ]);
                    } else {
                        switch ($jabatanPengawas) {
                            case 'JUNIOR FOREMAN':
                            case 'JUNIOR STAFF':
                                $allowedToVerify = in_array($userRole, [
                                    'FOREMAN', 'STAFF', 'SUPERVISOR',
                                    'PJS. SUPERINTENDENT', 'SUPERINTENDENT'
                                ]);
                                break;
                            case 'FOREMAN':
                            case 'STAFF':
                                $allowedToVerify = in_array($userRole, [
                                    'SUPERVISOR', 'PJS. SUPERINTENDENT', 'SUPERINTENDENT'
                                ]);
                                break;
                            case 'SUPERVISOR':
                                $allowedToVerify = in_array($userRole, [
                                    'PJS. SUPERINTENDENT', 'SUPERINTENDENT'
                                ]);
                                break;
                            case 'PJS. SUPERINTENDENT':
                                $allowedToVerify = in_array($userRole, ['SUPERINTENDENT']);
                                break;
                            case 'SUPERINTENDENT':
                                $allowedToVerify = in_array($userRole, ['MANAGER']);
                                break;
                            default:
                                $allowedToVerify = in_array($userRole, [
                                    'JUNIOR FOREMAN', 'FOREMAN', 'JUNIOR STAFF', 'STAFF',
                                    'SUPERVISOR', 'PJS. SUPERINTENDENT', 'SUPERINTENDENT'
                                ]);
                        }
                    }
                }

                // Tambahkan field baru ke response
                $row->CAN_VERIFY = $allowedToVerify;
                return $row;
        });

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil mengambil data KKH',
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalRecords,
                'data' => $data,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data KKH',
                'error' => $th->getMessage(),
            ], 500);
        }
    }


    public function name()
    {

        try {

            $user = DB::connection('sims')->table('db_payroll.dbo.tbl_data_hr as hr')
                ->leftJoin('db_payroll.dbo.tm_departemen as dp', 'hr.Id_Departemen', '=', 'dp.ID_Departemen')
                ->where('dp.Departemen', 'Planning')
                ->select(
                    'hr.Nik as nik',
                    'hr.Nama as name',
                )

                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil mengambil nama Planning',
                'data' => $user,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil nama Planning',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function verifikasi(Request $request, FirebaseService $firebase)
    {
        try {
            $rowID = $request->id;

            $target = DB::connection('sims')->table('web_kkh as kkh')
                ->leftJoin('tbl_data_hr as hr', 'kkh.nik', '=', 'hr.Nik')
                ->where('kkh.id', $rowID)
                ->select([
                    'hr.Nik as nik_hr',
                    'kkh.tgl',
                    'kkh.shift_kkh',
                ])
                ->first();

            if (!$target) {
                return response()->json([
                    'status'  => 'warning',
                    'message' => 'ID KKH tidak ditemukan.',
                ], 404);
            }

            $affected = DB::connection('sims')->table('web_kkh')
                ->where('id', $rowID)
                ->update([
                    'ferivikasi_pengawas' => 1,
                    'nik_pengawas'        => Auth::user()->nik,
                ]);

            if ($affected === 0) {
                return response()->json([
                    'status'  => 'warning',
                    'message' => 'Tidak ada baris yang diubah (mungkin sudah terverifikasi atau nilai sama).',
                ], 200);
            }

            $userNotif = \App\Models\User::where('nik', $target->nik_hr)->first();

            if ($userNotif && !empty($userNotif->fcm_token)) {
                $deviceToken = $userNotif->fcm_token;
                $verifikator = Auth::user()->name;

                $tanggal = $target->tgl
                    ? Carbon::parse($target->tgl)->locale('id')->translatedFormat('d M Y')
                    : '-';

                $shift = $target->shift_kkh ?? '-';

                $title = 'KKH Terverifikasi';
                $body  = "KKH Anda telah diverifikasi oleh {$verifikator} ( yang di Tanggal {$tanggal}, Shift {$shift}).";

                $firebase->sendNotification($deviceToken, $title, $body);
            }

            // 4) Respon sukses
            return response()->json([
                'status'  => 'success',
                'message' => 'Berhasil verifikasi KKH',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal verifikasi KKH',
                'error'   => $th->getMessage(),
            ], 500);
        }



    }

    public function verifikasiSelection(Request $request)
    {

        try {

            $rowID = $request->id;

            DB::connection('sims')->table('web_kkh')
                ->whereIn('id', $rowID)
                ->update([
                    'ferivikasi_pengawas' => true,
                    'nik_pengawas' => Auth::user()->nik,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil verifikasi KKH',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal verifikasi KKH',
                'error' => $th->getMessage(),
            ], 500);
        }

    }
}
