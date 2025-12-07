<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    //
    public function index()
    {
        try {

            $data = DB::connection('sqlsrv')
                ->table('PLANNING.dbo.users as us')
                ->leftJoin(DB::raw('sims.db_payroll.dbo.tbl_data_hr as hr'), 'us.nik', '=', 'hr.nik')
                ->leftJoin(DB::raw('sims.db_payroll.dbo.tm_departemen as dp'), 'hr.ID_Departemen', '=', 'dp.ID_Departemen')
                ->leftJoin(DB::raw('sims.db_payroll.dbo.tm_jabatan as jb'), 'hr.ID_Jabatan', '=', 'jb.ID_Jabatan')
                ->where('us.nik', Auth::user()->nik)
                ->select(
                    'hr.Nik as nik',
                    'hr.Nama as nama',
                    'dp.Departemen as departemen',
                    'jb.Jabatan as jabatan',
                    'hr.POB as tempat_lahir',
                    'hr.DOB as tanggal_lahir',
                    'hr.JK',
                    'hr.Agama as agama',
                    'hr.suku as suku',
                    'hr.No_Hp as no_hp',
                    'hr.Alamat_Email as email'
                )
                ->first();
                if ($data) {
                    if ($data->tanggal_lahir) {
                        $dob = Carbon::parse($data->tanggal_lahir);
                        $now = Carbon::now();

                        $diff = $dob->diff($now);

                        $years  = $diff->y;
                        $months = $diff->m;
                        $days   = $diff->d;

                        $data->usia = "{$years} tahun, {$months} bulan, {$days} hari";
                    } else {
                        $data->usia = null;
                    }

                    if ($data->JK === 'P') {
                        $data->JK = 'Perempuan';
                    } else {
                        $data->JK = 'Laki-laki';
                    }
                }

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil profile',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
