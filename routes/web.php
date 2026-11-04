<?php

Auth::routes();

//Route::get('/test', 'TestController@index')->name('test');
Route::get('/privacy-policy', 'HomeController@privacy_policy')->name('privacy_policy');
Route::get('/terms-of-use', 'HomeController@terms_of_use')->name('terms_of_use');


Route::group(['middleware' => 'auth'], function () {

    Route::get('/', 'HomeController@dashboard')->name('home');
    Route::get('/home', 'HomeController@dashboard')->name('home');
    Route::get('/dashboard', 'HomeController@dashboard')->name('dashboard');

    Route::group(['prefix' => 'my_account'], function() {
        Route::get('/', 'MyAccountController@edit_profile')->name('my_account');
        Route::put('/', 'MyAccountController@update_profile')->name('my_account.update');
        Route::put('/change_password', 'MyAccountController@change_pass')->name('my_account.change_pass');
    });

    Route::group(['prefix' => 'school'], function() {
        Route::get('/settings', 'SuperAdmin\\SettingController@index')->name('school.settings')->middleware('permission:settings.manage');
        Route::put('/settings', 'SuperAdmin\\SettingController@update')->name('school.settings.update')->middleware('permission:settings.manage');

        Route::get('/academic-periods', 'SupportTeam\\AcademicPeriodController@index')->name('academic_periods.index')->middleware('permission:academic_years.manage');
        Route::post('/academic-periods/years', 'SupportTeam\\AcademicPeriodController@storeYear')->name('academic_years.store')->middleware('permission:academic_years.manage');
        Route::put('/academic-periods/years/{academicYear}', 'SupportTeam\\AcademicPeriodController@updateYear')->name('academic_years.update')->middleware('permission:academic_years.manage');
        Route::delete('/academic-periods/years/{academicYear}', 'SupportTeam\\AcademicPeriodController@destroyYear')->name('academic_years.destroy')->middleware('permission:academic_years.manage');
        Route::post('/academic-periods/years/{academicYear}/terms', 'SupportTeam\\AcademicPeriodController@storeTerm')->name('terms.store')->middleware('permission:terms.manage');
        Route::put('/academic-periods/years/{academicYear}/terms/{term}', 'SupportTeam\\AcademicPeriodController@updateTerm')->name('terms.update')->middleware('permission:terms.manage');
        Route::delete('/academic-periods/years/{academicYear}/terms/{term}', 'SupportTeam\\AcademicPeriodController@destroyTerm')->name('terms.destroy')->middleware('permission:terms.manage');
    });

    Route::group(['prefix' => 'attendance', 'middleware' => 'teamSAT'], function() {
        Route::get('/', 'SupportTeam\\AttendanceController@index')->name('attendance.index')->middleware('permission:attendance.view');
        Route::post('/', 'SupportTeam\\AttendanceController@store')->name('attendance.store')->middleware('permission:attendance.mark');
        Route::get('/history', 'SupportTeam\\AttendanceController@history')->name('attendance.history')->middleware('permission:attendance.view');
        Route::get('/report/{status}', 'SupportTeam\\AttendanceController@statusReport')->where('status', 'absent|late|excused')->name('attendance.report')->middleware('permission:attendance.view');
        Route::get('/{attendanceSession}/print', 'SupportTeam\\AttendanceController@printView')->name('attendance.print')->middleware('permission:attendance.view');
        Route::get('/{attendanceSession}', 'SupportTeam\\AttendanceController@show')->name('attendance.show')->middleware('permission:attendance.view');
    });

    Route::get('/attendance/student/{student}', 'SupportTeam\\AttendanceController@studentHistory')->name('attendance.student');

    /*************** Support Team *****************/
    Route::group(['namespace' => 'SupportTeam',], function(){

        /*************** Students *****************/
        Route::group(['prefix' => 'students'], function(){
            Route::get('reset_pass/{st_id}', 'StudentRecordController@reset_pass')->name('st.reset_pass');
            Route::get('graduated', 'StudentRecordController@graduated')->name('students.graduated');
            Route::put('not_graduated/{id}', 'StudentRecordController@not_graduated')->name('st.not_graduated')->middleware('permission:students.manage');
            Route::get('list/{class_id}', 'StudentRecordController@listByClass')->name('students.list')->middleware('teamSAT');

            /* Promotions */
            Route::post('promote_selector', 'PromotionController@selector')->name('students.promote_selector');
            Route::get('promotion/manage', 'PromotionController@manage')->name('students.promotion_manage');
            Route::delete('promotion/reset/{pid}', 'PromotionController@reset')->name('students.promotion_reset');
            Route::delete('promotion/reset_all', 'PromotionController@reset_all')->name('students.promotion_reset_all');
            Route::get('promotion/{fc?}/{fs?}/{tc?}/{ts?}', 'PromotionController@promotion')->name('students.promotion');
            Route::post('promote/{fc}/{fs}/{tc}/{ts}', 'PromotionController@promote')->name('students.promote');

        });

        /*************** Users *****************/
        Route::group(['prefix' => 'users'], function(){
            Route::get('reset_pass/{id}', 'UserController@reset_pass')->name('users.reset_pass');
        });

        /*************** TimeTables *****************/
        Route::group(['prefix' => 'timetables'], function(){
            Route::get('/', 'TimeTableController@index')->name('tt.index');

            Route::group(['middleware' => 'teamSA'], function() {
                Route::post('/', 'TimeTableController@store')->name('tt.store');
                Route::put('/{tt}', 'TimeTableController@update')->name('tt.update');
                Route::delete('/{tt}', 'TimeTableController@delete')->name('tt.delete');
            });

            /*************** TimeTable Records *****************/
            Route::group(['prefix' => 'records'], function(){

                Route::group(['middleware' => 'teamSA'], function(){
                    Route::get('manage/{ttr}', 'TimeTableController@manage')->name('ttr.manage');
                    Route::post('/', 'TimeTableController@store_record')->name('ttr.store');
                    Route::get('edit/{ttr}', 'TimeTableController@edit_record')->name('ttr.edit');
                    Route::put('/{ttr}', 'TimeTableController@update_record')->name('ttr.update');
                });

                Route::get('show/{ttr}', 'TimeTableController@show_record')->name('ttr.show');
                Route::get('print/{ttr}', 'TimeTableController@print_record')->name('ttr.print');
                Route::delete('/{ttr}', 'TimeTableController@delete_record')->name('ttr.destroy');

            });

            /*************** Time Slots *****************/
            Route::group(['prefix' => 'time_slots', 'middleware' => 'teamSA'], function(){
                Route::post('/', 'TimeTableController@store_time_slot')->name('ts.store');
                Route::post('/use/{ttr}', 'TimeTableController@use_time_slot')->name('ts.use');
                Route::get('edit/{ts}', 'TimeTableController@edit_time_slot')->name('ts.edit');
                Route::delete('/{ts}', 'TimeTableController@delete_time_slot')->name('ts.destroy');
                Route::put('/{ts}', 'TimeTableController@update_time_slot')->name('ts.update');
            });

        });

        /*************** Payments *****************/
        Route::group(['prefix' => 'payments'], function(){

            Route::get('manage/{class_id?}', 'PaymentController@manage')->name('payments.manage');
            Route::get('invoice/{id}/{year?}', 'PaymentController@invoice')->name('payments.invoice');
            Route::get('receipts/{id}', 'PaymentController@receipts')->name('payments.receipts');
            Route::get('pdf_receipts/{id}', 'PaymentController@pdf_receipts')->name('payments.pdf_receipts');
            Route::post('select_year', 'PaymentController@select_year')->name('payments.select_year');
            Route::post('select_class', 'PaymentController@select_class')->name('payments.select_class');
            Route::delete('reset_record/{id}', 'PaymentController@reset_record')->name('payments.reset_record');
            Route::post('pay_now/{id}', 'PaymentController@pay_now')->name('payments.pay_now');
        });

        /*************** Pins *****************/
        Route::group(['prefix' => 'pins'], function(){
            Route::get('create', 'PinController@create')->name('pins.create');
            Route::get('/', 'PinController@index')->name('pins.index');
            Route::post('/', 'PinController@store')->name('pins.store');
            Route::get('enter/{id}', 'PinController@enter_pin')->name('pins.enter');
            Route::post('verify/{id}', 'PinController@verify')->name('pins.verify');
            Route::delete('/', 'PinController@destroy')->name('pins.destroy');
        });

        /*************** Marks *****************/
        Route::group(['prefix' => 'marks'], function(){

            // Mark changes and the edit workflow require the explicit manage permission.
            Route::group(['middleware' => 'permission:marks.manage'], function(){
                Route::get('batch_fix', 'MarkController@batch_fix')->name('marks.batch_fix');
                Route::put('batch_update', 'MarkController@batch_update')->name('marks.batch_update');
                Route::get('manage/{exam}/{class}/{section}/{subject}', 'MarkController@manage')->name('marks.manage');
                Route::put('update/{exam}/{class}/{section}/{subject}', 'MarkController@update')->name('marks.update');
                Route::put('comment_update/{exr_id}', 'MarkController@comment_update')->name('marks.comment_update');
                Route::put('skills_update/{skill}/{exr_id}', 'MarkController@skills_update')->name('marks.skills_update');
                Route::post('selector', 'MarkController@selector')->name('marks.selector');
            });

            // Read-only mark views require marks.view; retain TeamSAT for legacy access checks.
            Route::group(['middleware' => ['teamSAT', 'permission:marks.view']], function(){
                Route::get('/', 'MarkController@index')->name('marks.index');
                Route::get('tabulation/{exam?}/{class?}/{sec_id?}', 'MarkController@tabulation')->name('marks.tabulation');
                Route::post('tabulation', 'MarkController@tabulation_select')->name('marks.tabulation_select');
                Route::get('tabulation/print/{exam}/{class}/{sec_id}', 'MarkController@print_tabulation')->name('marks.print_tabulation');
                Route::get('bulk/{class?}/{section?}', 'MarkController@bulk')->name('marks.bulk');
                Route::post('bulk', 'MarkController@bulk_select')->name('marks.bulk_select');
            });

            Route::get('select_year/{id}', 'MarkController@year_selector')->name('marks.year_selector');
            Route::post('select_year/{id}', 'MarkController@year_selected')->name('marks.year_select');
            Route::get('show/{id}/{year}', 'MarkController@show')->name('marks.show');
            Route::get('print/{id}/{exam_id}/{year}', 'MarkController@print_view')->name('marks.print');

        });

        Route::resource('students', 'StudentRecordController');
        Route::resource('users', 'UserController');
        Route::resource('classes', 'MyClassController')->except(['create', 'show']);
        Route::resource('sections', 'SectionController')->except(['create', 'show']);
        Route::resource('subjects', 'SubjectController');
        Route::resource('grades', 'GradeController');
        Route::resource('exams', 'ExamController')->except(['create', 'show']);
        Route::resource('dorms', 'DormController');
        Route::resource('payments', 'PaymentController');

    });

    /************************ AJAX ****************************/
    Route::group(['prefix' => 'ajax'], function() {
        Route::get('get_lga/{state_id}', 'AjaxController@get_lga')->name('get_lga');
        Route::get('get_class_sections/{class_id}', 'AjaxController@get_class_sections')->name('get_class_sections');
        Route::get('get_class_subjects/{class_id}', 'AjaxController@get_class_subjects')->name('get_class_subjects');
    });

});

/************************ SUPER ADMIN ****************************/
Route::group(['namespace' => 'SuperAdmin','middleware' => 'super_admin', 'prefix' => 'super_admin'], function(){

    Route::get('/', 'DashboardController@index')->name('owner.dashboard');
    Route::get('/schools', 'SchoolController@index')->name('owner.schools.index');
    Route::get('/schools/create', 'SchoolController@create')->name('owner.schools.create');
    Route::post('/schools', 'SchoolController@store')->name('owner.schools.store');
    Route::get('/schools/{school}/edit', 'SchoolController@edit')->name('owner.schools.edit');
    Route::put('/schools/{school}', 'SchoolController@update')->name('owner.schools.update');
    Route::get('/audit-logs', 'AuditLogController@index')->name('owner.audit_logs.index');
    Route::get('/permissions', 'RolePermissionController@index')->name('owner.permissions.index');
    Route::put('/permissions/{role}', 'RolePermissionController@update')->name('owner.permissions.update');

});

/************************ PARENT ****************************/
Route::group(['namespace' => 'MyParent','middleware' => 'my_parent',], function(){

    Route::get('/my_children', 'MyController@children')->name('my_children');

});
