<?php

namespace App\Http\Controllers;

use App\Models\SOP;
use Illuminate\Http\Request;

class SOPController extends Controller
{
    //
    public function index(Request $request)
    {
        try {
            $data = SOP::where('STATUSENABLED', true);

            $name      = $request->input('name');
            if (!empty($name) && $name !== 'Semua') {
                $data->where('NAME', $name);
            }

            $data = $data->orderBy('NO_URUT')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil mengambil daftar SOP',
                'data' => $data,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil daftar SOP',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function file($uuid)
    {
        $file = SOP::where('UUID', $uuid)->first();

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'File SOP tidak ditemukan'
            ], 404);
        }

        $fileUrl = "http://36.67.119.212:9011/sop/{$file->FILE}";

        return response()->json([
            'success' => true,
            'data' => [
                'uuid' => $file->UUID,
                'namaFile' => $file->NAME,
                'url' => $fileUrl,
            ],
        ]);
    }


    public function name()
    {

        try {

            $data = SOP::where('STATUSENABLED', true)->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil mengambil nama SOP',
                'data' => $data,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil nama SOP',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
