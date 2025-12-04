<?php

use App\Http\Controllers\AbsensiController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\FCMTokenController;
use App\Http\Controllers\KKHController;
use App\Http\Controllers\KLKHFuelStationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SOPController;
use App\Http\Controllers\UserDiketahuiController;
use Illuminate\Support\Facades\Route;


Route::post('/login', [AuthController::class, 'login'])->name('login');

//Banner
Route::get('/banner', [BannerController::class, 'index']);

//Area
Route::get('/area', [AreaController::class, 'index']);



//Shift
Route::get('/shift', [ShiftController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    //Auth logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    //KLKH Fuel Station
    Route::post('/klkh/fuel-station', [KLKHFuelStationController::class, 'store']);
    Route::get('/klkh/fuel-station', [KLKHFuelStationController::class, 'index']);
    Route::put('/klkh/fuel-station/{id}', [KLKHFuelStationController::class, 'update']);
    Route::delete('/klkh/fuel-station/{id}', [KLKHFuelStationController::class, 'destroy']);
    Route::get('/klkh/fuel-station/preview/{id}', [KLKHFuelStationController::class, 'preview']);
    Route::get('/klkh/fuel-station/edit/{id}', [KLKHFuelStationController::class, 'edit']);
    Route::put('/klkh/fuel-station/update/{id}', [KLKHFuelStationController::class, 'update']);
    Route::get('/klkh/fuel-station/download/{id}', [KLKHFuelStationController::class, 'download']);
    Route::put('/klkh/fuel-station/verified/all', [KLKHFuelStationController::class, 'verifiedAll']);
    Route::put('/klkh/fuel-station/verified/pengawas', [KLKHFuelStationController::class, 'verifiedPengawas']);
    Route::put('/klkh/fuel-station/verified/diketahui', [KLKHFuelStationController::class, 'verifiedDiketahui']);

    //KKH
    Route::get('/kkh', [KKHController::class, 'index']);
    Route::get('/kkh/name', [KKHController::class, 'name']);
    Route::put('/kkh/verifikasi', [KKHController::class, 'verifikasi']);
    Route::put('/kkh/verifikasi/selection', [KKHController::class, 'verifikasiSelection']);

    //SOP
    Route::get('/sop', [SOPController::class, 'index']);
    Route::get('/sop/name', [SOPController::class, 'name']);
    Route::get('/sop/file/{uuid}', [SOPController::class, 'file']);

    //User Diketahui
    Route::get('/users/diketahui', [UserDiketahuiController::class, 'index']);

    //Absensi
    Route::get('/absensi', [AbsensiController::class, 'index']);

    Route::put('/fcm-token', [FCMTokenController::class, 'store']);

    //Notification
    Route::post('/send-notification', [NotificationController::class, 'sendNotification']);
    Route::post('/send-campaign', [NotificationController::class, 'sendCampaign']);
    Route::get('/notification', [NotificationController::class, 'index']);
    Route::put('/read-notification/{id}', [NotificationController::class, 'readNotification']);

    //Activity
    Route::get('/activity/summary', [ActivityController::class, 'summary']);
    Route::get('/activity/all', [ActivityController::class, 'all']);
});
