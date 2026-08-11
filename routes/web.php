<?php

use App\Http\Controllers\Auth\AuthenticateUserController;
use App\Http\Controllers\Auth\LogoutUserController;
use App\Http\Controllers\CertificateDocumentDownloadController;
use App\Http\Controllers\CertificateExportController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\MotorcycleSerialRequestController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductExportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\VehicleIdentificationRecordManagementController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'index')->name('home');
Route::view('/login', 'login')->middleware('guest')->name('login');
Route::post('/login', AuthenticateUserController::class)
    ->middleware(['guest', 'throttle:5,1'])
    ->name('login.authenticate');
Route::view('/dashboard', 'dashboard')
    ->middleware(['auth', 'password.not_expired'])
    ->name('dashboard');
Route::view('/parametros-del-sistema', 'system-settings.index')
    ->middleware(['auth', 'password.not_expired', 'permission:system-settings.view'])
    ->name('system_settings.index');
Route::get('/despacho', [DispatchController::class, 'index'])
    ->middleware(['auth', 'password.not_expired', 'permission:dispatches.view'])
    ->name('dispatches.index');
Route::get('/despacho/nuevo', [DispatchController::class, 'create'])
    ->middleware(['auth', 'password.not_expired', 'permission:dispatches.create'])
    ->name('dispatches.create');
Route::get('/despacho/{dispatch}', [DispatchController::class, 'edit'])
    ->middleware(['auth', 'password.not_expired', 'permission:dispatches.view'])
    ->name('dispatches.edit');
Route::get('/devoluciones', [ReturnController::class, 'index'])
    ->middleware(['auth', 'password.not_expired', 'permission:returns.view'])
    ->name('returns.index');
Route::get('/devoluciones/nueva', [ReturnController::class, 'create'])
    ->middleware(['auth', 'password.not_expired', 'permission:returns.view'])
    ->name('returns.create');
Route::get('/devoluciones/{return}', [ReturnController::class, 'edit'])
    ->middleware(['auth', 'password.not_expired', 'permission:returns.view'])
    ->name('returns.edit');
Route::view('/maestro-seriales-certificados', 'certificates.index')
    ->middleware(['auth', 'password.not_expired', 'permission:certificates.view'])
    ->name('certificates.index');
Route::get('/maestro-seriales-certificados/exportar', CertificateExportController::class)
    ->middleware(['auth', 'password.not_expired', 'permission:certificates.view'])
    ->name('certificates.export');
Route::view('/certificados', 'certificate-documents.index')
    ->middleware(['auth', 'password.not_expired', 'permission:certificate-documents.view'])
    ->name('certificate_documents.index');
Route::get('/certificados/{certificateDocument}/descargar', CertificateDocumentDownloadController::class)
    ->middleware(['auth', 'password.not_expired', 'permission:certificate-documents.view'])
    ->name('certificate_documents.download');
Route::view('/correccion-maestro-seriales-certificados', 'certificate-corrections.index')
    ->middleware(['auth', 'password.not_expired', 'permission:certificate-corrections.view'])
    ->name('certificate_corrections.index');
Route::view('/productos', 'products.index')
    ->middleware(['auth', 'password.not_expired', 'permission:products.view'])
    ->name('products.index');
Route::get('/productos/exportar', ProductExportController::class)
    ->middleware(['auth', 'password.not_expired', 'permission:products.view'])
    ->name('products.export');
Route::get('/productos/nuevo', [ProductController::class, 'create'])
    ->middleware(['auth', 'password.not_expired', 'permission:products.create'])
    ->name('products.create');
Route::get('/productos/{product}', [ProductController::class, 'edit'])
    ->middleware(['auth', 'password.not_expired', 'permission:products.view'])
    ->name('products.edit');
Route::get('/solicitud-seriales-motos', [MotorcycleSerialRequestController::class, 'index'])
    ->middleware(['auth', 'password.not_expired', 'permission:motorcycle-serial-requests.view'])
    ->name('motorcycle_serial_requests.index');
Route::get('/solicitud-seriales-motos/nueva', [MotorcycleSerialRequestController::class, 'create'])
    ->middleware(['auth', 'password.not_expired', 'permission:motorcycle-serial-requests.create'])
    ->name('motorcycle_serial_requests.create');
Route::get('/solicitud-seriales-motos/{motorcycleSerialRequest}', [MotorcycleSerialRequestController::class, 'edit'])
    ->middleware(['auth', 'password.not_expired', 'permission:motorcycle-serial-requests.view'])
    ->name('motorcycle_serial_requests.edit');
Route::view('/constancia-registro-niv', 'vehicle-identification-record.index')
    ->middleware(['auth', 'password.not_expired', 'permission:vehicle-identification-record.view'])
    ->name('vehicle_identification_records.index');
Route::get('/gestion-constancia-registro-niv', [VehicleIdentificationRecordManagementController::class, 'index'])
    ->middleware(['auth', 'password.not_expired', 'permission:vehicle-identification-record-management.view'])
    ->name('vehicle_identification_record_management.index');
Route::get('/gestion-constancia-registro-niv/{management}', [VehicleIdentificationRecordManagementController::class, 'edit'])
    ->middleware(['auth', 'password.not_expired', 'permission:vehicle-identification-record-management.view'])
    ->name('vehicle_identification_record_management.edit');
Route::post('/logout', LogoutUserController::class)
    ->middleware('auth')
    ->name('logout');

Route::view('/users', 'users.index')
    ->middleware(['auth', 'password.not_expired', 'permission:users.view'])
    ->name('users.index');
Route::get('/users/nuevo', [UserManagementController::class, 'create'])
    ->middleware(['auth', 'password.not_expired', 'permission:users.create'])
    ->name('users.create');
Route::get('/users/{user}', [UserManagementController::class, 'edit'])
    ->middleware(['auth', 'password.not_expired', 'permission:users.view'])
    ->name('users.edit');
