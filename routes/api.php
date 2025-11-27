<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\VerifyApiCsrfToken;


use App\Http\Controllers\UserAPIController;
use App\Http\Controllers\CategoryAPIController;
use App\Http\Controllers\RoleAPIController;
use App\Http\Controllers\DocumentAPIController;
use App\Http\Controllers\ReminderAPIController;
use App\Http\Controllers\SectorAPIController;
use App\Http\Controllers\AttributeAPIController;
use App\Http\Controllers\BulkUploadAPIController;
use App\Http\Controllers\SettingsAPIController;
use App\Http\Controllers\FTPAPIController;
use App\Http\Controllers\ADController;
use App\Http\Controllers\AIController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

//User Routes
Route::post('/login', [UserAPIController::class, 'login']);
Route::post('/login-with-ad', [UserAPIController::class, 'login_with_ad']);
Route::post('/add-user', [UserAPIController::class, 'add_user'])->middleware('auth:api');
Route::match(['get', 'post'],'/user-details/{id}', [UserAPIController::class, 'user_details'])->middleware('auth:api');
Route::post('/update-password', [UserAPIController::class, 'update_password'])->middleware('auth:api');
Route::get('/delete-user/{id}', [UserAPIController::class, 'delete_user'])->middleware('auth:api');
Route::get('/users', [UserAPIController::class, 'users'])->middleware('auth:api');
Route::get('/login-audits', [UserAPIController::class, 'login_audits'])->middleware('auth:api');
Route::get('/user-permissions/{id}', [UserAPIController::class, 'user_permissions'])->middleware('auth:api');
Route::post('/role-user', [UserAPIController::class, 'role_user'])->middleware('auth:api');
Route::get('/users-by-role/{id}', [UserAPIController::class, 'users_by_role'])->middleware('auth:api');
Route::post('/remove-role-user', [UserAPIController::class, 'remove_role_user'])->middleware('auth:api');
Route::post('/forgot-password', [UserAPIController::class, 'forgot_password']);
Route::post('/reset-password', [UserAPIController::class, 'reset_password']);
Route::get('/get-supervisors', [UserAPIController::class, 'get_supervisors'])->middleware('auth:api');

//Category Routes
Route::post('/add-category', [CategoryAPIController::class, 'add_category'])->middleware('auth:api');
Route::match(['get', 'post'],'/category-details/{id}', [CategoryAPIController::class, 'category_details'])->middleware('auth:api');
Route::get('/delete-category/{id}', [CategoryAPIController::class, 'delete_category'])->middleware('auth:api');
Route::get('/categories', [CategoryAPIController::class, 'categories'])->middleware('auth:api');
Route::get('/categories-with-childs', [CategoryAPIController::class, 'categories_with_childs'])->middleware('auth:api');
Route::get('/categories-with-doc-count', [CategoryAPIController::class, 'categories_with_doc_count'])->middleware('auth:api');

//Roles Routes
Route::post('/add-role', [RoleAPIController::class, 'add_role'])->middleware('auth:api');
Route::match(['get', 'post'],'/role-details/{id}', [RoleAPIController::class, 'role_details'])->middleware('auth:api');
Route::get('/delete-role/{id}', [RoleAPIController::class, 'delete_role'])->middleware('auth:api');
Route::get('/roles', [RoleAPIController::class, 'roles'])->middleware('auth:api');

//Document Routes
Route::post('/add-document', [DocumentAPIController::class, 'add_document'])->middleware('auth:api');
Route::match(['get', 'post'],'/edit-document/{id}', [DocumentAPIController::class, 'edit_document'])->middleware('auth:api');
Route::get('/delete-document/{id}/{user}', [DocumentAPIController::class, 'delete_document'])->middleware('auth:api');
Route::get('/documents', [DocumentAPIController::class, 'documents'])->middleware('auth:api');
Route::get('/unapproved-documents', [DocumentAPIController::class, 'unapproved_documents'])->middleware('auth:api');
Route::get('/view-document/{id}/{user}', [DocumentAPIController::class, 'view_document'])->middleware('auth:api');
Route::get('/download-document/{id}/{user}', [DocumentAPIController::class, 'download_document'])->middleware('auth:api');
Route::match(['get', 'post'],'/document-share/{id}', [DocumentAPIController::class, 'document_share'])->middleware('auth:api');
Route::post('/document-bulk-share', [DocumentAPIController::class, 'document_bulk_share'])->middleware('auth:api');
Route::get('/delete-share/{type}/{id}', [DocumentAPIController::class, 'delete_share'])->middleware('auth:api');
Route::match(['get', 'post'],'/get-shareble-link/{id}', [DocumentAPIController::class, 'get_shareble_link'])->middleware('auth:api');
Route::match(['get', 'post'],'/unlock-shareble-link/{id}', [DocumentAPIController::class, 'unlock_shareble_link'])->middleware('auth:api');
Route::get('/delete-shareble-link/{id}/{user}', [DocumentAPIController::class, 'delete_shareble_link'])->middleware('auth:api');
Route::post('/document-upload-new-version/{id}', [DocumentAPIController::class, 'document_upload_new_version'])->middleware('auth:api');
Route::get('/document-version-history/{id}', [DocumentAPIController::class, 'document_version_history'])->middleware('auth:api');
Route::match(['get', 'post'],'/document-comments/{id}', [DocumentAPIController::class, 'document_comments'])->middleware('auth:api');
Route::get('/delete-comment/{id}/{user}', [DocumentAPIController::class, 'delete_comment'])->middleware('auth:api');
Route::post('/document-send-email/{id}', [DocumentAPIController::class, 'document_send_email'])->middleware('auth:api');
Route::post('/document-archive/{id}', [DocumentAPIController::class, 'document_archive'])->middleware('auth:api');
Route::post('/document-remove-index/{id}', [DocumentAPIController::class, 'document_remove_index'])->middleware('auth:api');
Route::get('/document-audit-trial', [DocumentAPIController::class, 'document_audit_trial'])->middleware('auth:api');
Route::get('/archived-documents', [DocumentAPIController::class, 'archived_documents'])->middleware('auth:api');
Route::get('/assigned-documents', [DocumentAPIController::class, 'assigned_documents'])->middleware('auth:api');
Route::get('/assigned-documents-by-user/{id}', [DocumentAPIController::class, 'assigned_documents_by_user'])->middleware('auth:api');
Route::get('/restore-archived-document/{id}/{user}', [DocumentAPIController::class, 'restore_archived_document'])->middleware('auth:api');
Route::post('/filter-assigned-documents', [DocumentAPIController::class, 'filter_assigned_documents'])->middleware('auth:api');
Route::post('/filter-assigned-documents-by-user/{id}', [DocumentAPIController::class, 'filter_assigned_documents_by_user'])->middleware('auth:api');
Route::post('/filter-all-documents', [DocumentAPIController::class, 'filter_all_documents'])->middleware('auth:api');
Route::post('/filter-audit-trial', [DocumentAPIController::class, 'filter_audit_trial'])->middleware('auth:api');
Route::post('/filter-archived-documents', [DocumentAPIController::class, 'filter_archived_documents'])->middleware('auth:api');
Route::post('/deep-search', [DocumentAPIController::class, 'deep_search'])->middleware('auth:api');
Route::post('/advanced-search', [DocumentAPIController::class, 'advanced_search'])->middleware('auth:api');
Route::get('/deleted-documents', [DocumentAPIController::class, 'deleted_documents'])->middleware('auth:api');
Route::get('/restore-deleted-document/{id}', [DocumentAPIController::class, 'restore_deleted_document'])->middleware('auth:api');
Route::get('/delete-document-permanently/{id}', [DocumentAPIController::class, 'delete_document_permanently'])->middleware('auth:api');
Route::post('/bulk-delete-documents', [DocumentAPIController::class, 'bulk_delete_documents'])->middleware('auth:api');
Route::post('/bulk-permanently-delete-documents', [DocumentAPIController::class, 'bulk_permanently_delete_documents'])->middleware('auth:api');
Route::post('/get-document-text/{id}', [DocumentAPIController::class, 'get_document_text'])->middleware('auth:api');
Route::post('/document-approve/{id}', [DocumentAPIController::class, 'document_approve'])->middleware('auth:api');
Route::get('/approval-history', [DocumentAPIController::class, 'approval_history'])->middleware('auth:api');
Route::get('unapproved-documents', [DocumentAPIController::class, 'unapproved_documents'])->middleware('auth:api');



//Reminder Routes
Route::post('/reminder', [ReminderAPIController::class, 'reminder'])->middleware('auth:api');
Route::match(['get', 'post'],'/edit-reminder/{id}', [ReminderAPIController::class, 'edit_reminder'])->middleware('auth:api');
Route::get('/reminders', [ReminderAPIController::class, 'reminders'])->middleware('auth:api');
Route::get('/reminders-user', [ReminderAPIController::class, 'reminders_user'])->middleware('auth:api');
Route::get('/delete-reminder/{id}', [ReminderAPIController::class, 'delete_reminder'])->middleware('auth:api');
Route::post('/filter-reminders', [ReminderAPIController::class, 'filter_reminders'])->middleware('auth:api');
Route::post('/filter-reminders', [ReminderAPIController::class, 'filter_reminders'])->middleware('auth:api');


//Sector Routes
Route::post('/add-sector', [SectorAPIController::class, 'add_sector'])->middleware('auth:api');
Route::match(['get', 'post'],'/sector-details/{id}', [SectorAPIController::class, 'sector_details'])->middleware('auth:api');
Route::get('/delete-sector/{id}', [SectorAPIController::class, 'delete_sector'])->middleware('auth:api');
Route::get('/sectors', [SectorAPIController::class, 'sectors'])->middleware('auth:api');
Route::get('/sectors/{id}', [SectorAPIController::class, 'sectors_by_id'])->middleware('auth:api');
Route::get('/all-sectors', [SectorAPIController::class, 'all_sectors'])->middleware('auth:api');

//Attribute Routes
Route::post('/add-attribute', [AttributeAPIController::class, 'add_attribute'])->middleware('auth:api');
Route::match(['get', 'post'],'/attribute-details/{id}', [AttributeAPIController::class, 'attribute_details'])->middleware('auth:api');
Route::get('/delete-attribute/{id}', [AttributeAPIController::class, 'delete_attribute'])->middleware('auth:api');
Route::get('/attributes', [AttributeAPIController::class, 'attributes'])->middleware('auth:api');
Route::get('/attribute-by-category/{id}', [AttributeAPIController::class, 'attribute_by_category'])->middleware('auth:api');

//Bulk Upload
Route::match(['get', 'post'],'/bulk-upload', [BulkUploadAPIController::class, 'bulk_upload'])->middleware('auth:api');
Route::match(['get', 'post'],'/save-bulk-document', [BulkUploadAPIController::class, 'save_bulk_document'])->middleware('auth:api');
Route::get('/bulk-upload-delete-file/{id}', [BulkUploadAPIController::class, 'bulk_upload_delete_file'])->middleware('auth:api');
Route::match(['get', 'post'],'/excel-bulk-upload', [BulkUploadAPIController::class, 'excel_bulk_upload'])->middleware('auth:api');
Route::match(['get', 'post'],'/excel-bulk-upload-confirm', [BulkUploadAPIController::class, 'excel_bulk_upload_confirm'])->middleware('auth:api');
Route::match(['get', 'post'],'/save-bulk-document-excel/{id}', [BulkUploadAPIController::class, 'save_bulk_document_excel'])->middleware('auth:api');
Route::post('/save-bulk-document-excel-bulk', [BulkUploadAPIController::class, 'save_bulk_document_excel_bulk'])->middleware('auth:api');
Route::get('/bulk-upload-excel-delete-record/{id}', [BulkUploadAPIController::class, 'bulk_upload_excel_delete_record'])->middleware('auth:api');
Route::get('/bulk-upload-excel-delete-file/{id}', [BulkUploadAPIController::class, 'bulk_upload_excel_delete_file'])->middleware('auth:api');
Route::match(['get', 'post'],'/process-documents', [BulkUploadAPIController::class, 'process_documents'])->middleware('auth:api');
Route::match(['get', 'post'],'/generate-excel-with-file-names', [BulkUploadAPIController::class, 'generate_excel_with_file_names'])->middleware('auth:api');


//Settings Routes
Route::post('/add-smtp', [SettingsAPIController::class, 'add_smtp'])->middleware('auth:api');
Route::get('/all-smtps', [SettingsAPIController::class, 'all_smtps'])->middleware('auth:api');
Route::match(['get', 'post'],'/smtp-details/{id}', [SettingsAPIController::class, 'smtp_details'])->middleware('auth:api');
Route::get('/delete-smtp/{id}', [SettingsAPIController::class, 'delete_smtp'])->middleware('auth:api');;
Route::match(['get', 'post'],'/company-profile', [SettingsAPIController::class, 'company_profile']);
Route::post('/company-profile-storage', [SettingsAPIController::class, 'company_profile_storage'])->middleware('auth:api');
Route::get('/get-ad-connection', [SettingsAPIController::class, 'get_ad_connection']);

//FTP Routes
Route::post('/add-ftp-account', [FTPAPIController::class, 'add_ftp_account'])->middleware('auth:api');
Route::get('/ftp-accounts', [FTPAPIController::class, 'ftp_accounts'])->middleware('auth:api');
Route::match(['get', 'post'],'/edit-ftp-account/{id}', [FTPAPIController::class, 'edit_ftp_account'])->middleware('auth:api');
Route::get('/delete-ftp-account/{id}', [FTPAPIController::class, 'delete_ftp_account'])->middleware('auth:api');;
Route::get('/view-ftp-file', [FTPAPIController::class, 'view_ftp_file'])->middleware('auth:api');



//AD Routes
Route::get('/import-users', [ADController::class, 'import_users'])->middleware('auth:api');
Route::match(['get', 'post'],'/ad-credentials', [ADController::class, 'ad_credentials'])->middleware('auth:api');


Route::get('/search', [DocumentAPIController::class, 'search']);

//AI
//QA
Route::get('/initialize-chat/{id}', [AIController::class, 'initialize_chat'])->middleware('auth:api');
Route::post('/qa-chat', [AIController::class, 'qa_chat'])->middleware('auth:api');
Route::get('/delete-vectors/{id}', [AIController::class, 'delete_vectors'])->middleware('auth:api');

//Summarize
Route::get('/summarize-document/{id}', [AIController::class, 'summarize_document'])->middleware('auth:api');

//Translate
Route::post('/translate-document', [AIController::class, 'translate_document'])->middleware('auth:api');

//Tone
Route::get('/get-tone/{id}', [AIController::class, 'get_tone'])->middleware('auth:api');
Route::post('/covert-document-tone', [AIController::class, 'covert_document_tone'])->middleware('auth:api');

//Generate
Route::post('/generate-document-content', [AIController::class, 'generate_document_content'])->middleware('auth:api');