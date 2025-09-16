<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\KLKHFuelStation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTime;
use Illuminate\Support\Facades\File;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Services\FirebaseService;

class KLKHFuelStationController extends Controller
{

    public function index(Request $request)
    {
        try {
            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            if (!$startDate || !$endDate) {
                // Default ke hari ini jika tidak ada input
                $startDate = Carbon::now()->toDateString();
                $endDate = $startDate;
            }

            $user = Auth::user();
            $nik = $user->nik;
            $role = $user->role;

            $fuelStation = DB::table('KLKH_FUEL_STATION as fs')
                ->leftJoin('users as us1', 'fs.PIC', '=', 'us1.nik')
                ->leftJoin('REF_AREA as ar', 'fs.PIT_ID', '=', 'ar.id')
                ->leftJoin('REF_SHIFT as sh', 'fs.SHIFT_ID', '=', 'sh.id')
                ->leftJoin('users as us2', 'fs.PENGAWAS', '=', 'us2.nik')
                ->leftJoin('users as us3', 'fs.DIKETAHUI', '=', 'us3.nik')
                ->select(
                    'fs.ID',
                    'fs.UUID',
                    'fs.PIC as NIK_PIC',
                    'us1.name as NAMA_PIC',
                    DB::raw('CONVERT(varchar, fs.CREATED_AT, 120) as TANGGAL_PEMBUATAN'),
                    'fs.STATUSENABLED',
                    'ar.KETERANGAN as PIT',
                    'sh.KETERANGAN as SHIFT',
                    'fs.PENGAWAS as NIK_PENGAWAS',
                    'fs.VERIFIED_PENGAWAS as VERIFIED_PENGAWAS',
                    'fs.VERIFIED_DATETIME_PENGAWAS as VERIFIED_DATETIME_PENGAWAS',
                    'fs.CATATAN_VERIFIED_PENGAWAS as CATATAN_VERIFIED_PENGAWAS',
                    'us2.name as NAMA_PENGAWAS',
                    'fs.DIKETAHUI as NIK_DIKETAHUI',
                    'fs.VERIFIED_DIKETAHUI as VERIFIED_DIKETAHUI',
                    'fs.VERIFIED_DATETIME_DIKETAHUI as VERIFIED_DATETIME_DIKETAHUI',
                    'fs.CATATAN_VERIFIED_DIKETAHUI as CATATAN_VERIFIED_DIKETAHUI',
                    'us3.name as NAMA_DIKETAHUI',
                    'fs.DATE',
                    DB::raw("CONVERT(VARCHAR(5), fs.TIME, 108) as TIME"),
                )
                ->where('fs.STATUSENABLED', true)
                ->whereBetween(DB::raw('CONVERT(varchar, fs.DATE, 23)'), [$startDate, $endDate]);

            // 🔐 Filter berdasarkan role user login
            // $fuelStation->where(function ($query) use ($nik) {
            //     $query->where('fs.PIC', $nik)
            //         ->orWhere('fs.PENGAWAS', $nik)
            //         ->orWhere('fs.DIKETAHUI', $nik);
            // });

            if (in_array(Auth::user()->role, ['FUELMAN', 'OPERATOR'])) {
                $fuelStation->whereRaw('1 = 0');
            }
            $fuelStation->orderBy('fs.CREATED_AT', 'desc');

            $result = $fuelStation->get();

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data Fuel Station.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function preview($id)
    {
        try {
            $fuelStation = DB::table('KLKH_FUEL_STATION as fs')
                ->leftJoin('users as us1', 'fs.PIC', '=', 'us1.nik')
                ->leftJoin('REF_AREA as ar', 'fs.PIT_ID', '=', 'ar.id')
                ->leftJoin('REF_SHIFT as sh', 'fs.SHIFT_ID', '=', 'sh.id')
                ->leftJoin('users as us2', 'fs.PENGAWAS', '=', 'us2.nik')
                ->leftJoin('users as us3', 'fs.DIKETAHUI', '=', 'us3.nik')
                ->select(
                    'fs.*',
                    'ar.KETERANGAN as PIT',
                    'sh.KETERANGAN as SHIFT',
                    'us1.name as NAMA_PIC',
                    'us2.name as NAMA_PENGAWAS',
                    'us3.name as NAMA_DIKETAHUI',
                    DB::raw("CASE
                    WHEN fs.DIKETAHUI = '".Auth::user()->nik."'
                         AND fs.VERIFIED_DIKETAHUI IS NULL
                    THEN 1
                    ELSE 0
                 END as check_verified")
                )
                ->where('fs.statusenabled', true)
                ->where('fs.id', $id)
                ->first();

            if ($fuelStation == null) {
                return redirect()->back()->with('info', 'Maaf, data tidak ditemukan');
            } else {
                $item = $fuelStation;

                $qrTempFolder = storage_path('app/public/qr-temp');
                if (!File::exists($qrTempFolder)) {
                    File::makeDirectory($qrTempFolder, 0755, true);
                }

                if ($item->VERIFIED_PENGAWAS != null) {
                    $qrContent = 'http://planning.ptsims.co.id/verified/' . base64_encode($item->VERIFIED_PENGAWAS);
                    $qrImage = base64_encode(QrCode::format('png')->size(150)->generate($qrContent));
                    $item->VERIFIED_PENGAWAS = 'data:image/png;base64,' . $qrImage;
                } else {
                    $item->VERIFIED_PENGAWAS = null;
                }

                if ($item->VERIFIED_DIKETAHUI != null) {
                    $qrContent = 'http://planning.ptsims.co.id/verified/' . base64_encode($item->VERIFIED_DIKETAHUI);
                    $qrImage = base64_encode(QrCode::format('png')->size(150)->generate($qrContent));
                    $item->VERIFIED_DIKETAHUI = 'data:image/png;base64,' . $qrImage;
                } else {
                    $item->VERIFIED_DIKETAHUI = null;
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $item,
                ], 200);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data Fuel Station.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function edit($id)
    {
        try {
            $fuelStation = DB::table('KLKH_FUEL_STATION as fs')
                ->leftJoin('users as us1', 'fs.PIC', '=', 'us1.nik')
                ->leftJoin('REF_AREA as ar', 'fs.PIT_ID', '=', 'ar.id')
                ->leftJoin('REF_SHIFT as sh', 'fs.SHIFT_ID', '=', 'sh.id')
                ->leftJoin('users as us2', 'fs.PENGAWAS', '=', 'us2.nik')
                ->leftJoin('users as us3', 'fs.DIKETAHUI', '=', 'us3.nik')
                ->select(
                    'fs.*',
                    'ar.KETERANGAN as PIT',
                    'sh.KETERANGAN as SHIFT',
                    'us1.name as NAMA_PIC',
                    'us2.name as NAMA_PENGAWAS',
                    'us3.name as NAMA_DIKETAHUI',
                )
                ->where('fs.statusenabled', true)
                ->where('fs.id', $id)
                ->first();

            if ($fuelStation == null) {
                return redirect()->back()->with('info', 'Maaf, data tidak ditemukan');
            } else {
                $item = $fuelStation;

                $qrTempFolder = storage_path('app/public/qr-temp');
                if (!File::exists($qrTempFolder)) {
                    File::makeDirectory($qrTempFolder, 0755, true);
                }

                if ($item->VERIFIED_PENGAWAS != null) {
                    $qrContent = 'http://planning.ptsims.co.id/verified/' . base64_encode($item->VERIFIED_PENGAWAS);
                    $qrImage = base64_encode(QrCode::format('png')->size(150)->generate($qrContent));
                    $item->VERIFIED_PENGAWAS = 'data:image/png;base64,' . $qrImage;
                } else {
                    $item->VERIFIED_PENGAWAS = null;
                }

                if ($item->VERIFIED_DIKETAHUI != null) {
                    $qrContent = 'http://planning.ptsims.co.id/verified/' . base64_encode($item->VERIFIED_DIKETAHUI);
                    $qrImage = base64_encode(QrCode::format('png')->size(150)->generate($qrContent));
                    $item->VERIFIED_DIKETAHUI = 'data:image/png;base64,' . $qrImage;
                } else {
                    $item->VERIFIED_DIKETAHUI = null;
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $item,
                ], 200);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data Fuel Station.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, FirebaseService $firebase)
    {
        try {
            $data = $request->all();

            $dataToInsert = [
                'PIC' => Auth::user()->nik,
                'UUID' => (string) Uuid::uuid4()->toString(),
                'STATUSENABLED' => true,
                'PIT_ID' => $data['PIT'],
                'SHIFT_ID' => $data['SHIFT'],
                'DATE' => $data['DATE'],
                'TIME' => $data['TIME'],
                'PERMUKAAN_TANAH_RATA_CHECK' => $data['PERMUKAAN_TANAH_RATA_CHECK'],
                'PERMUKAAN_TANAH_RATA_NOTE' => $data['PERMUKAAN_TANAH_RATA_NOTE'] ?? null,
                'PERMUKAAN_TANAH_LICIN_CHECK' => $data['PERMUKAAN_TANAH_LICIN_CHECK'],
                'PERMUKAAN_TANAH_LICIN_NOTE' => $data['PERMUKAAN_TANAH_LICIN_NOTE'] ?? null,
                'LOKASI_JAUH_LINTASAN_CHECK' => $data['LOKASI_JAUH_LINTASAN_CHECK'],
                'LOKASI_JAUH_LINTASAN_NOTE' => $data['LOKASI_JAUH_LINTASAN_NOTE'] ?? null,
                'TIDAK_CECERAN_B3_CHECK' => $data['TIDAK_CECERAN_B3_CHECK'],
                'TIDAK_CECERAN_B3_NOTE' => $data['TIDAK_CECERAN_B3_NOTE'] ?? null,
                'PARKIR_FUELTRUCK_CHECK' => $data['PARKIR_FUELTRUCK_CHECK'],
                'PARKIR_FUELTRUCK_NOTE' => $data['PARKIR_FUELTRUCK_NOTE'] ?? null,
                'PARKIR_LV_CHECK' => $data['PARKIR_LV_CHECK'],
                'PARKIR_LV_NOTE' => $data['PARKIR_LV_NOTE'] ?? null,
                'LAMPU_KERJA_CHECK' => $data['LAMPU_KERJA_CHECK'],
                'LAMPU_KERJA_NOTE' => $data['LAMPU_KERJA_NOTE'] ?? null,
                'FUEL_GENSET_CHECK' => $data['FUEL_GENSET_CHECK'],
                'FUEL_GENSET_NOTE' => $data['FUEL_GENSET_NOTE'] ?? null,
                'AIR_BERSIH_TANDON_CHECK' => $data['AIR_BERSIH_TANDON_CHECK'],
                'AIR_BERSIH_TANDON_NOTE' => $data['AIR_BERSIH_TANDON_NOTE'] ?? null,
                'SOP_JSA_CHECK' => $data['SOP_JSA_CHECK'],
                'SOP_JSA_NOTE' => $data['SOP_JSA_NOTE'] ?? null,
                'SAFETY_POST_CHECK' => $data['SAFETY_POST_CHECK'],
                'SAFETY_POST_NOTE' => $data['SAFETY_POST_NOTE'] ?? null,
                'RAMBU_APD_CHECK' => $data['RAMBU_APD_CHECK'],
                'RAMBU_APD_NOTE' => $data['RAMBU_APD_NOTE'] ?? null,
                'PERLENGKAPAN_KERJA_CHECK' => $data['PERLENGKAPAN_KERJA_CHECK'],
                'PERLENGKAPAN_KERJA_NOTE' => $data['PERLENGKAPAN_KERJA_NOTE'] ?? null,
                'APAB_APAR_CHECK' => $data['APAB_APAR_CHECK'],
                'APAB_APAR_NOTE' => $data['APAB_APAR_NOTE'] ?? null,
                'P3K_EYEWASH_CHECK' => $data['P3K_EYEWASH_CHECK'],
                'P3K_EYEWASH_NOTE' => $data['P3K_EYEWASH_NOTE'] ?? null,
                'INSPEKSI_APAR_CHECK' => $data['INSPEKSI_APAR_CHECK'],
                'INSPEKSI_APAR_NOTE' => $data['INSPEKSI_APAR_NOTE'] ?? null,
                'FORM_CHECKLIST_REFUELING_CHECK' => $data['FORM_CHECKLIST_REFUELING_CHECK'],
                'FORM_CHECKLIST_REFUELING_NOTE' => $data['FORM_CHECKLIST_REFUELING_NOTE'] ?? null,
                'TEMPAT_SAMPAH_CHECK' => $data['TEMPAT_SAMPAH_CHECK'],
                'TEMPAT_SAMPAH_NOTE' => $data['TEMPAT_SAMPAH_NOTE'] ?? null,
                'MINEPERMIT_CHECK' => $data['MINEPERMIT_CHECK'],
                'MINEPERMIT_NOTE' => $data['MINEPERMIT_NOTE'] ?? null,
                'SIMPER_OPERATOR_CHECK' => $data['SIMPER_OPERATOR_CHECK'],
                'SIMPER_OPERATOR_NOTE' => $data['SIMPER_OPERATOR_NOTE'] ?? null,
                'PADLOCK_CHECK' => $data['PADLOCK_CHECK'],
                'PADLOCK_NOTE' => $data['PADLOCK_NOTE'] ?? null,
                'WADAH_PENAMPUNG_CHECK' => $data['WADAH_PENAMPUNG_CHECK'],
                'WADAH_PENAMPUNG_NOTE' => $data['WADAH_PENAMPUNG_NOTE'] ?? null,
                'WHEEL_CHOCK_CHECK' => $data['WHEEL_CHOCK_CHECK'],
                'WHEEL_CHOCK_NOTE' => $data['WHEEL_CHOCK_NOTE'] ?? null,
                'RADIO_KOMUNIKASI_CHECK' => $data['RADIO_KOMUNIKASI_CHECK'],
                'RADIO_KOMUNIKASI_NOTE' => $data['RADIO_KOMUNIKASI_NOTE'] ?? null,
                'APD_STANDAR_CHECK' => $data['APD_STANDAR_CHECK'],
                'APD_STANDAR_NOTE' => $data['APD_STANDAR_NOTE'] ?? null,
                'ADDITIONAL_NOTES' => $data['ADDITIONAL_NOTES'] ?? null,
                'DIKETAHUI' => $data['DIKETAHUI'] ?? null,
                'PENGAWAS' => Auth::user()->nik,
                'VERIFIED_DATETIME_PENGAWAS' => Carbon::now(),
                'VERIFIED_PENGAWAS' => Auth::user()->nik,
            ];

            // if (Auth::user()->role == 'JUNIOR FOREMAN' || Auth::user()->role == 'FOREMAN' || Auth::user()->role == 'JUNIOR STAFF' || Auth::user()->role == 'STAFF' || Auth::user()->role == 'SUPERVISOR') {
            //     $dataToInsert['PENGAWAS'] = Auth::user()->nik;
            //     $dataToInsert['VERIFIED_DATETIME_PENGAWAS'] = Carbon::now();
            //     $dataToInsert['VERIFIED_PENGAWAS'] = Auth::user()->nik;
            // }

            KLKHFuelStation::create($dataToInsert);

            Activity::create([
                'STATUSENABLED' => true,
                'TANGGAL' => Carbon::now(),
                'JENIS' => 'KLKH',
                'NAMA' => Auth::user()->name,
                'NIK' => Auth::user()->nik,
                'KETERANGAN' => 'Telah menambahkan KLKH Fuel Station',
            ]);

            $userNotif = User::where('nik', $data['DIKETAHUI'])->first();
            $deviceToken = $userNotif->fcm_token;
            $title = Auth::user()->name;
            $body  = 'KLKH telah berhasil dibuat, mohon untuk diperiksa!';

            $firebase->sendNotification($deviceToken, $title, $body);

            return response()->json([
                'status' => 'success',
                'message' => 'KLKH Fuel Station berhasil dibuat',
            ], 201);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal membuat KLKH Fuel Station',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();

            $dataToUpdate = [
                'PIC' => Auth::user()->nik,
                'STATUSENABLED' => true,
                'PIT_ID' => $data['PIT'],
                'SHIFT_ID' => $data['SHIFT'],
                'DATE' => $data['DATE'],
                'TIME' => $data['TIME'],
                'PERMUKAAN_TANAH_RATA_CHECK' => $data['PERMUKAAN_TANAH_RATA_CHECK'],
                'PERMUKAAN_TANAH_RATA_NOTE' => $data['PERMUKAAN_TANAH_RATA_NOTE'] ?? null,
                'PERMUKAAN_TANAH_LICIN_CHECK' => $data['PERMUKAAN_TANAH_LICIN_CHECK'],
                'PERMUKAAN_TANAH_LICIN_NOTE' => $data['PERMUKAAN_TANAH_LICIN_NOTE'] ?? null,
                'LOKASI_JAUH_LINTASAN_CHECK' => $data['LOKASI_JAUH_LINTASAN_CHECK'],
                'LOKASI_JAUH_LINTASAN_NOTE' => $data['LOKASI_JAUH_LINTASAN_NOTE'] ?? null,
                'TIDAK_CECERAN_B3_CHECK' => $data['TIDAK_CECERAN_B3_CHECK'],
                'TIDAK_CECERAN_B3_NOTE' => $data['TIDAK_CECERAN_B3_NOTE'] ?? null,
                'PARKIR_FUELTRUCK_CHECK' => $data['PARKIR_FUELTRUCK_CHECK'],
                'PARKIR_FUELTRUCK_NOTE' => $data['PARKIR_FUELTRUCK_NOTE'] ?? null,
                'PARKIR_LV_CHECK' => $data['PARKIR_LV_CHECK'],
                'PARKIR_LV_NOTE' => $data['PARKIR_LV_NOTE'] ?? null,
                'LAMPU_KERJA_CHECK' => $data['LAMPU_KERJA_CHECK'],
                'LAMPU_KERJA_NOTE' => $data['LAMPU_KERJA_NOTE'] ?? null,
                'FUEL_GENSET_CHECK' => $data['FUEL_GENSET_CHECK'],
                'FUEL_GENSET_NOTE' => $data['FUEL_GENSET_NOTE'] ?? null,
                'AIR_BERSIH_TANDON_CHECK' => $data['AIR_BERSIH_TANDON_CHECK'],
                'AIR_BERSIH_TANDON_NOTE' => $data['AIR_BERSIH_TANDON_NOTE'] ?? null,
                'SOP_JSA_CHECK' => $data['SOP_JSA_CHECK'],
                'SOP_JSA_NOTE' => $data['SOP_JSA_NOTE'] ?? null,
                'SAFETY_POST_CHECK' => $data['SAFETY_POST_CHECK'],
                'SAFETY_POST_NOTE' => $data['SAFETY_POST_NOTE'] ?? null,
                'RAMBU_APD_CHECK' => $data['RAMBU_APD_CHECK'],
                'RAMBU_APD_NOTE' => $data['RAMBU_APD_NOTE'] ?? null,
                'PERLENGKAPAN_KERJA_CHECK' => $data['PERLENGKAPAN_KERJA_CHECK'],
                'PERLENGKAPAN_KERJA_NOTE' => $data['PERLENGKAPAN_KERJA_NOTE'] ?? null,
                'APAB_APAR_CHECK' => $data['APAB_APAR_CHECK'],
                'APAB_APAR_NOTE' => $data['APAB_APAR_NOTE'] ?? null,
                'P3K_EYEWASH_CHECK' => $data['P3K_EYEWASH_CHECK'],
                'P3K_EYEWASH_NOTE' => $data['P3K_EYEWASH_NOTE'] ?? null,
                'INSPEKSI_APAR_CHECK' => $data['INSPEKSI_APAR_CHECK'],
                'INSPEKSI_APAR_NOTE' => $data['INSPEKSI_APAR_NOTE'] ?? null,
                'FORM_CHECKLIST_REFUELING_CHECK' => $data['FORM_CHECKLIST_REFUELING_CHECK'],
                'FORM_CHECKLIST_REFUELING_NOTE' => $data['FORM_CHECKLIST_REFUELING_NOTE'] ?? null,
                'TEMPAT_SAMPAH_CHECK' => $data['TEMPAT_SAMPAH_CHECK'],
                'TEMPAT_SAMPAH_NOTE' => $data['TEMPAT_SAMPAH_NOTE'] ?? null,
                'MINEPERMIT_CHECK' => $data['MINEPERMIT_CHECK'],
                'MINEPERMIT_NOTE' => $data['MINEPERMIT_NOTE'] ?? null,
                'SIMPER_OPERATOR_CHECK' => $data['SIMPER_OPERATOR_CHECK'],
                'SIMPER_OPERATOR_NOTE' => $data['SIMPER_OPERATOR_NOTE'] ?? null,
                'PADLOCK_CHECK' => $data['PADLOCK_CHECK'],
                'PADLOCK_NOTE' => $data['PADLOCK_NOTE'] ?? null,
                'WADAH_PENAMPUNG_CHECK' => $data['WADAH_PENAMPUNG_CHECK'],
                'WADAH_PENAMPUNG_NOTE' => $data['WADAH_PENAMPUNG_NOTE'] ?? null,
                'WHEEL_CHOCK_CHECK' => $data['WHEEL_CHOCK_CHECK'],
                'WHEEL_CHOCK_NOTE' => $data['WHEEL_CHOCK_NOTE'] ?? null,
                'RADIO_KOMUNIKASI_CHECK' => $data['RADIO_KOMUNIKASI_CHECK'],
                'RADIO_KOMUNIKASI_NOTE' => $data['RADIO_KOMUNIKASI_NOTE'] ?? null,
                'APD_STANDAR_CHECK' => $data['APD_STANDAR_CHECK'],
                'APD_STANDAR_NOTE' => $data['APD_STANDAR_NOTE'] ?? null,
                'ADDITIONAL_NOTES' => $data['ADDITIONAL_NOTES'] ?? null,
                'DIKETAHUI' => $data['DIKETAHUI'] ?? null,
                'PENGAWAS' => Auth::user()->nik,
                'VERIFIED_DATETIME_PENGAWAS' => Carbon::now(),
                'VERIFIED_PENGAWAS' => Auth::user()->nik,
            ];

            // if (Auth::user()->role == 'JUNIOR FOREMAN' || Auth::user()->role == 'FOREMAN' || Auth::user()->role == 'JUNIOR STAFF' || Auth::user()->role == 'STAFF' || Auth::user()->role == 'SUPERVISOR') {
            //     $dataToInsert['PENGAWAS'] = Auth::user()->nik;
            //     $dataToInsert['VERIFIED_DATETIME_PENGAWAS'] = Carbon::now();
            //     $dataToInsert['VERIFIED_PENGAWAS'] = Auth::user()->nik;
            // }

            KLKHFuelStation::where('ID', $id)->update($dataToUpdate);

            Activity::create([
                'STATUSENABLED' => true,
                'TANGGAL' => Carbon::now(),
                'JENIS' => 'KLKH',
                'NAMA' => Auth::user()->name,
                'NIK' => Auth::user()->nik,
                'KETERANGAN' => 'Telah mengupdate KLKH Fuel Station',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'KLKH Fuel Station berhasil diupdate',
            ], 201);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengupdate KLKH Fuel Station',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $klkh = KLKHFuelStation::where('ID', $id)->first();
        try {
            $deleted = KLKHFuelStation::where('ID', $id)->update([
                'STATUSENABLED' => false,
                'DELETED_BY' => Auth::user()->nik,
            ]);

            if ($deleted) {

                Activity::create([
                    'STATUSENABLED' => true,
                    'TANGGAL' => Carbon::now(),
                    'JENIS' => 'KLKH',
                    'NAMA' => Auth::user()->name,
                    'NIK' => Auth::user()->nik,
                    'KETERANGAN' => 'Telah menghapus KLKH Fuel Station',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'KLKH Fuel Station berhasil dihapus',
                ], 200);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak ditemukan atau gagal dihapus.',
                ], 404);
            }

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menghapus data.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function download($id)
    {
        $fuelStation = DB::table('KLKH_FUEL_STATION as fs')
            ->leftJoin('users as us1', 'fs.PIC', '=', 'us1.nik')
            ->leftJoin('REF_AREA as ar', 'fs.PIT_ID', '=', 'ar.id')
            ->leftJoin('REF_SHIFT as sh', 'fs.SHIFT_ID', '=', 'sh.id')
            ->leftJoin('users as us2', 'fs.PENGAWAS', '=', 'us2.nik')
            ->leftJoin('users as us3', 'fs.DIKETAHUI', '=', 'us3.nik')
            ->select(
                'fs.*',
                'fs.STATUSENABLED',
                'ar.KETERANGAN as PIT',
                'sh.KETERANGAN as SHIFT',
                'us2.name as NAMA_PENGAWAS',
                'us3.name as NAMA_DIKETAHUI'
            )
            ->where('fs.STATUSENABLED', true)
            ->where('fs.ID', $id)
            ->first();

        if (!$fuelStation) {
            return redirect()->back()->with('info', 'Maaf, data tidak ditemukan');
        }

        $item = $fuelStation;

        $qrTempFolder = public_path('qr-temp');
        if (!File::exists($qrTempFolder)) {
            File::makeDirectory($qrTempFolder, 0755, true);
        }

        if ($item->VERIFIED_PENGAWAS != null) {
            $fileName = 'VERIFIED_PENGAWAS' . $item->UUID . '.png';
            $filePath = $qrTempFolder . DIRECTORY_SEPARATOR . $fileName;

            QrCode::size(150)->format('png')->generate(
                'http://planner.ptsims.co.id/verified/' . base64_encode($item->VERIFIED_PENGAWAS),
                $filePath
            );

            $item->QR_PENGAWAS_PATH = $filePath; // untuk dompdf (lokal)
        } else {
            $item->QR_PENGAWAS_PATH = null;
        }

        if ($item->VERIFIED_DIKETAHUI != null) {
            $fileName = 'VERIFIED_DIKETAHUI' . $item->UUID . '.png';
            $filePath = $qrTempFolder . DIRECTORY_SEPARATOR . $fileName;

            QrCode::size(150)->format('png')->generate(
                'http://planner.ptsims.co.id/verified/' . base64_encode($item->VERIFIED_DIKETAHUI),
                $filePath
            );

            $item->QR_DIKETAHUI_PATH = $filePath;
        } else {
            $item->QR_DIKETAHUI_PATH = null;
        }

        // kirim objek ke PDF
        $pdf = PDF::loadView('klkh.fuelStation.download', ['fuelStation' => $item]);
        return $pdf->download('KLKH Fuel Station.pdf');
    }

    public function verifiedAll(Request $request, $id)
    {
        $klkh =  KLKHFuelStation::where('ID', $id)->first();
        try {
            KLKHFuelStation::where('ID', $klkh->ID)->update([
                'VERIFIED_PENGAWAS' => $klkh->PENGAWAS,
                'VERIFIED_DATETIME_PENGAWAS' => Carbon::now(),
                'VERIFIED_DIKETAHUI' => $klkh->DIKETAHUI,
                'VERIFIED_DATETIME_DIKETAHUI' => Carbon::now(),
                'UPDATED_BY' => Auth::user()->id,
                // 'CATATAN_VERIFIED_PENGAWAS' => $request->CATATAN_VERIFIED_ALL,
                // 'CATATAN_VERIFIED_DIKETAHUI' => $request->CATATAN_VERIFIED_ALL,
            ]);

            return response()->json([
                    'status' => 'success',
                    'message' => 'KLKH Fuel Station berhasil diverifikasi',
                ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat verifikasi data.',
                'error' => $th->getMessage(),
            ], 500);
        }

    }

    public function verifiedPengawas(Request $request)
    {

        $klkh =  KLKHFuelStation::where('ID', $request->id)->first();
        try {
            KLKHFuelStation::where('ID', $klkh->ID)->update([
                'VERIFIED_PENGAWAS' => (string)Auth::user()->nik,
                'VERIFIED_DATETIME_PENGAWAS' => Carbon::now(),
                'UPDATED_BY' => Auth::user()->id,
                'CATATAN_VERIFIED_PENGAWAS' => $request->catatan_verified_pengawas,
            ]);

            return response()->json([
                    'status' => 'success',
                    'message' => 'KLKH Fuel Station berhasil diverifikasi',
                ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat verifikasi data.',
                'error' => $th->getMessage(),
            ], 500);
        }

    }

    public function verifiedDiketahui(Request $request, FirebaseService $firebase)
    {

        $klkh =  KLKHFuelStation::where('ID', $request->id)->first();
        try {
            KLKHFuelStation::where('ID', $klkh->ID)->update([
                'VERIFIED_DIKETAHUI' => (string)Auth::user()->nik,
                'VERIFIED_DATETIME_DIKETAHUI' => Carbon::now(),
                'UPDATED_BY' => Auth::user()->id,
                'CATATAN_VERIFIED_DIKETAHUI' => $request->catatan_verified_diketahui,
            ]);

            Activity::create([
                'STATUSENABLED' => true,
                'TANGGAL' => Carbon::now(),
                'JENIS' => 'KLKH',
                'NAMA' => Auth::user()->name,
                'NIK' => Auth::user()->nik,
                'KETERANGAN' => 'Telah memverifikasi KLKH Fuel Station',
            ]);

            $userPengawas = User::where('nik', $klkh->PENGAWAS)->first();
            $userDiketahui = User::where('nik', $klkh->DIKETAHUI)->first();
            $deviceToken = $userPengawas->fcm_token;
            $title = Auth::user()->name;
            $body  = 'KLKH anda telah berhasil diverikasi, mohon untuk diperiksa!';

            $firebase->sendNotification($deviceToken, $title, $body);

            return response()->json([
                    'status' => 'success',
                    'message' => 'KLKH Fuel Station berhasil diverifikasi',
                ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat verifikasi data.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
