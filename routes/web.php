<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\EmiPlanActionController;
use App\Http\Controllers\EmiPlanController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePaymentController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientPaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalaryPaymentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/brand/logo', [BrandController::class, 'logo'])->name('brand.logo');
Route::get('/brand/login-image', [BrandController::class, 'loginImage'])->name('brand.login-image');

Route::middleware(['auth', 'active', 'branch'])->group(function () {
    Route::post('/branch/switch', BranchSwitchController::class)->name('branch.switch');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/help', 'help')->name('help');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Patients: reception/admin manage, doctors view
    Route::middleware('permission:patients.view')->group(function () {
        Route::get('/patients/search', [PatientController::class, 'search'])->name('patients.search');
        Route::get('/patients/{patient}/open-bills', [PatientController::class, 'openBills'])->middleware('permission:invoices.view')->name('patients.open-bills');
        Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    });
    Route::middleware('permission:patients.manage')->group(function () {
        Route::get('/patients/create', [PatientController::class, 'create'])->name('patients.create');
        Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
        Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit');
        Route::put('/patients/{patient}', [PatientController::class, 'update'])->name('patients.update');
    });
    Route::get('/patients/{patient}', [PatientController::class, 'show'])
        ->middleware('permission:patients.view')->name('patients.show');

    // Visits
    Route::middleware('permission:visits.view')->group(function () {
        Route::get('/visits', [VisitController::class, 'index'])->name('visits.index');
    });
    Route::middleware('permission:visits.manage')->group(function () {
        Route::get('/visits/create', [VisitController::class, 'create'])->name('visits.create');
        Route::post('/visits', [VisitController::class, 'store'])->name('visits.store');
        Route::get('/visits/{visit}/edit', [VisitController::class, 'edit'])->name('visits.edit');
        Route::put('/visits/{visit}', [VisitController::class, 'update'])->name('visits.update');
    });
    Route::get('/visits/{visit}', [VisitController::class, 'show'])->middleware('permission:visits.view')->name('visits.show');

    // Billing
    Route::middleware('permission:invoices.view')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    });
    Route::middleware('permission:invoices.create')->group(function () {
        Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    });
    Route::middleware('permission:invoices.view')->group(function () {
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    });
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoices.cancel')->name('invoices.cancel');
    Route::post('/invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])->middleware('permission:payments.collect')->name('invoices.payments.store');

    Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:payments.collect')->name('payments.index');
    Route::get('/receipts/batch/{ref}', [PaymentController::class, 'batch'])->whereUuid('ref')->name('payments.batch');
    Route::get('/receipts/batch/{ref}/pdf', [PaymentController::class, 'batchPdf'])->whereUuid('ref')->name('payments.batch.pdf');
    Route::post('/patients/{patient}/payments', [PatientPaymentController::class, 'store'])->middleware('permission:payments.collect')->name('patients.payments.store');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
    Route::get('/payments/{payment}/receipt.pdf', [PaymentController::class, 'pdf'])->name('payments.receipt.pdf');

    // EMI plans
    Route::middleware('permission:emi.view')->group(function () {
        Route::get('/emi-plans', [EmiPlanController::class, 'index'])->name('emi-plans.index');
        Route::get('/emi-dues', [EmiPlanController::class, 'dues'])->name('emi-plans.dues');
    });
    Route::middleware('permission:emi.manage')->group(function () {
        Route::get('/emi-plans/create', [EmiPlanController::class, 'create'])->name('emi-plans.create');
        Route::post('/emi-plans/preview', [EmiPlanController::class, 'preview'])->name('emi-plans.preview');
        Route::post('/emi-plans', [EmiPlanController::class, 'store'])->name('emi-plans.store');
        Route::post('/emi-plans/{emiPlan}/payments', [EmiPlanActionController::class, 'collect'])->name('emi-plans.collect');
    });
    Route::get('/emi-plans/{emiPlan}', [EmiPlanController::class, 'show'])->middleware('permission:emi.view')->name('emi-plans.show');
    Route::post('/emi-plans/{emiPlan}/reschedule', [EmiPlanActionController::class, 'reschedule'])->middleware('permission:emi.reschedule')->name('emi-plans.reschedule');
    Route::middleware('permission:emi.cancel')->group(function () {
        Route::post('/emi-plans/{emiPlan}/cancel', [EmiPlanActionController::class, 'cancel'])->name('emi-plans.cancel');
        Route::post('/emi-plans/{emiPlan}/default', [EmiPlanActionController::class, 'markDefaulted'])->name('emi-plans.default');
        Route::post('/emi-plans/{emiPlan}/reactivate', [EmiPlanActionController::class, 'reactivate'])->name('emi-plans.reactivate');
    });

    // Appointments
    Route::middleware('permission:appointments.manage')->group(function () {
        Route::get('/appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/appointments/{appointment}/edit', [AppointmentController::class, 'edit'])->name('appointments.edit');
        Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
        Route::post('/appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])->name('appointments.confirm');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::post('/appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])->name('appointments.no-show');
        Route::post('/appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])->name('appointments.check-in');
    });
    Route::middleware('permission:appointments.view')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::get('/appointments/{appointment}/slip', [AppointmentController::class, 'slip'])->name('appointments.slip');
    });

    // Follow-ups
    Route::get('/follow-ups', [FollowUpController::class, 'index'])->middleware('permission:followups.view')->name('follow-ups.index');
    Route::middleware('permission:followups.manage')->group(function () {
        Route::get('/follow-ups/create', [FollowUpController::class, 'create'])->name('follow-ups.create');
        Route::post('/follow-ups', [FollowUpController::class, 'store'])->name('follow-ups.store');
        Route::post('/follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])->name('follow-ups.complete');
        Route::post('/follow-ups/{followUp}/reschedule', [FollowUpController::class, 'reschedule'])->name('follow-ups.reschedule');
        Route::post('/follow-ups/{followUp}/cancel', [FollowUpController::class, 'cancel'])->name('follow-ups.cancel');
    });
    Route::get('/follow-ups/{followUp}', [FollowUpController::class, 'show'])->middleware('permission:followups.view')->name('follow-ups.show');

    // Attendance
    Route::middleware('permission:attendance.view')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('/attendance/monthly', [AttendanceController::class, 'monthly'])->name('attendance.monthly');
    });
    Route::post('/attendance', [AttendanceController::class, 'store'])->middleware('permission:attendance.manage')->name('attendance.store');

    // Employees & salary entries (admin)
    Route::middleware('permission:employees.manage')->group(function () {
        Route::post('/employees/sync-logins', [EmployeeController::class, 'syncLogins'])->name('employees.sync-logins');
        Route::resource('employees', EmployeeController::class);
    });
    Route::middleware('permission:salary.manage')->group(function () {
        Route::resource('salary-payments', SalaryPaymentController::class)->except('show');
    });

    // Reports
    Route::middleware('permission:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/{report}', [ReportController::class, 'show'])->name('show');
        Route::get('/{report}/excel', [ReportController::class, 'excel'])->name('excel');
        Route::get('/{report}/pdf', [ReportController::class, 'pdf'])->name('pdf');
    });

    // Branches (admin)
    Route::middleware('permission:branches.manage')->group(function () {
        Route::resource('branches', BranchController::class)->except(['show', 'destroy']);
    });

    // Audit log (admin)
    Route::get('/audit-log', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');

    // Adding a service from inside a bill (anyone who can bill; the full catalogue stays admin-only)
    Route::post('/services/quick', [ServiceController::class, 'quickStore'])->middleware('permission:invoices.create')->name('services.quick-store');

    // Masters (admin only via masters.manage)
    Route::middleware('permission:masters.manage')->group(function () {
        Route::resource('departments', DepartmentController::class)->except('show');
        Route::resource('doctors', DoctorController::class)->except('show');
        Route::resource('services', ServiceController::class)->except('show');
    });

    // Admin only
    Route::middleware('role:admin')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    });
});

require __DIR__.'/auth.php';
