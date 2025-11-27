<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Support\Facades\Http;
use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Roles;
use App\Models\Documents;
use App\Models\DocumentSharedRoles;
use App\Models\DocumentSharedUsers;
use App\Models\DocumentSharedLinks;
use App\Models\DocumentVersions;
use App\Models\DocumentComments;
use App\Models\BulkUpload;
use App\Models\BulkUploadExcel;
use App\Models\Attribute;
use App\Models\FTPAccounts;
use App\Models\BulkUploadExcelConfirmed;
use App\Models\CompanyProfile;

class BulkUploadAPIController extends Controller
{

   
public function bulk_upload(Request $request)
{
    if($request->isMethod('get')){
        $documents = BulkUpload::select('id','type', 'name')->orderBy('id', 'DESC')->get();

        return response()->json([
            'status' => "success",
            'documents' => $documents
        ], 201);
    }
    if($request->isMethod('post')){
    try {
        $validator = Validator::make($request->all(), [
            'documents' => 'nullable|array',
            'documents.*' => 'file', 
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "fail",
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {

                // $sanitizedFileName = str_replace(' ', '_', $file->getClientOriginalName());
                // $file_name = time() . '_' . $sanitizedFileName;
                $file_name = time() . '-' . Str::uuid()->toString() . '.' . $file->extension();

                $filePath = $file->storeAs('temp_documents', $file_name, 'local');

                BulkUpload::create([
                    'type' => $file->extension(),
                    'name' => $file_name,
                    'file_path' => $filePath,
                ]);
            }
        }

        $documents = BulkUpload::select('type', 'name')->orderBy('id', 'DESC')->get();

        return response()->json([
            'status' => "success",
            'message' => 'Documents added.',
            'documents' => $documents
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
    }
}
public function save_bulk_document(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'bulk_document_id' => 'required',
            'name' => 'required',
            'category' => 'required',
            'storage' => 'required',
            'user' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "fail",
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

            $bulk_file_details = BulkUpload::where('id', $request->bulk_document_id)->first();

            $file_name = $bulk_file_details->name;
            $filePath = $bulk_file_details->file_path;

            Storage::copy($filePath, 'documents/' . $file_name);

            Storage::delete($filePath);
            
            BulkUpload::where('id', $request->bulk_document_id)->delete();

            $user = auth('api')->user();
            $roles = json_decode($user->role, true);

            $roleId = $roles[0] ?? null;
            $role = $roleId ? Roles::find($roleId) : null;

            $is_approved = ($role && $role->needs_approval == 1) ? 0 : 1;

            $document = new Documents();
            $document->name = $request->name;
            $document->type = $bulk_file_details->type;
            $document->category = $request->category;
            $document->sector_category = $request->sector_category;
            $document->storage = $request->storage;
            $document->description = $request->description; 
            $document->meta_tags = $request->meta_tags;
            $document->file_path = 'documents/' . $file_name;
            $document->uploaded_method = 'direct';
            $document->attributes = $request->attribute_data;
            $document->is_approved = $is_approved;
            $document->save();
            
            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document added','document', $request->user, $document->id, $date_time, $request->assigned_roles, $request->assigned_users);
            
            $version = new DocumentVersions();
            $version->document_id = $document->id;
            $version->type = $bulk_file_details->type;
            $version->file_path = 'documents/' . $file_name;
            $version->date_time = $date_time;
            $version->user = $request->user;
            $version->save();
            
   
        if ($request->assigned_roles) {
            foreach (json_decode($request->assigned_roles, true) as $assigned_role) {
                $shared_role = new DocumentSharedRoles();
                $shared_role->document_id = $document->id;
                $shared_role->role = $assigned_role;
                $shared_role->is_time_limited = $request->role_is_time_limited;
                $shared_role->start_date_time = $request->role_start_date_time;
                $shared_role->end_date_time = $request->role_end_date_time;
                $shared_role->is_downloadable = $request->role_is_downloadable;
                $shared_role->save();
            }
        }

        if ($request->assigned_users) {
            foreach (json_decode($request->assigned_users, true) as $assigned_user) {
                $shared_user = new DocumentSharedUsers();
                $shared_user->document_id = $document->id;
                $shared_user->user = $assigned_user;
                $shared_user->is_time_limited = $request->user_is_time_limited;
                $shared_user->start_date_time = $request->user_start_date_time;
                $shared_user->end_date_time = $request->user_end_date_time;
                $shared_user->is_downloadable = $request->user_is_downloadable;
                $shared_user->save();
            }
        }

        return response()->json([
            'status' => "success",
            'message' => 'Document added.'
        ], 201);
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function bulk_upload_delete_file($id,Request $request)
{
     
    try {
        BulkUpload::where('id', '=', $id)->delete();
            return response()->json([
                'status' => "success",
                'message' => 'File Deleted'
            ], 201);
        
    
    } catch (\Exception $e) {

        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }    
}
// public function excel_bulk_upload(Request $request)
// {
//     if($request->isMethod('get')){
//         $documents = BulkUploadExcel::orderBy('id', 'DESC')->get();

//         return response()->json([
//             'status' => "success",
//             'documents' => $documents
//         ], 201);
//     }
//     if($request->isMethod('post')){
//     try {
//         $validator = Validator::make($request->all(), [
//             'upload_file' => 'required',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'status' => "fail",
//                 'message' => 'Validation errors',
//                 'errors' => $validator->errors()
//             ], 422);
//         }

//         $file = $request->file('upload_file');


//         $data = Excel::toArray([], $file);

//         $rows = $data[0];

//         $header = array_shift($rows);

//         foreach ($rows as $row) {
//             $record = array_combine($header, $row);
        
//             $metaTags = explode(',', $record['MetaTags'] ?? '');
//             $formattedMetaTags = json_encode(array_map('trim', $metaTags));
        
//             $categoryAttributes = Attribute::where('category', $request->category)->value('attributes');
//             $formattedAttributes = [];
        
//             if ($categoryAttributes) {

//                 $categoryAttributes = json_decode($categoryAttributes, true);
        
//                 foreach ($categoryAttributes as $attribute) {

//                     if (isset($record[$attribute])) {
//                         $formattedAttributes[] = [
//                             'value' => $record[$attribute], 
//                             'attribute' => $attribute,  
//                         ];
//                     }
//                 }
//             }
        
//             $formattedAttributesJson = json_encode($formattedAttributes);

//             BulkUploadExcel::create([
//                 'name' => $record['FileNameToShow'] ?? '',
//                 'category' => $request->category ?? '',
//                 'sector_category' => $request->sector_category ?? '',
//                 'description' => $record['Description'] ?? '',
//                 'meta_tags' => $formattedMetaTags,
//                 'file_path' => ($request->file_path ?? '') . '/' . ($record['FileName'] ?? ''),
//                 'attribute_data' => $formattedAttributesJson,
//                 'storage' => $request->ftp_account ?? '',
//             ]);
//         }
        
        
//         return response()->json([
//             'status' => "success",
//             'message' => 'Documents added.',
//         ], 201);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => "fail",
//             'message' => 'Request failed',
//             'error' => $e->getMessage()
//         ], 500);
//     }
//     }
// }
// public function excel_bulk_upload(Request $request)
// {
//     if ($request->isMethod('get')) {
//         $documents = BulkUploadExcel::orderBy('id', 'DESC')->get();

//         return response()->json([
//             'status' => "success",
//             'documents' => $documents
//         ], 200);
//     }

//     if ($request->isMethod('post')) {
//         try {

//             $validator = Validator::make($request->all(), [
//                 'upload_file' => 'required',
//                 'category' => 'required',
//                 'sector_category' => 'required',
//                 'file_path' => 'required',
//                 'ftp_account' => 'required',
//                 'extension' => 'required',
//                 'row_from' => 'required',
//                 'row_to' => 'required',
//             ]);
    
//             if ($validator->fails()) {
//                 return response()->json([
//                     'status' => "fail",
//                     'message' => 'Validation errors',
//                     'errors' => $validator->errors()
//                 ], 422);
//             }

//             $attributes = Attribute::where('category', $request->category)->value('attributes');

//             $file_name = time() . '.' . $request->upload_file->extension();
//             $filePath = $request->upload_file->storeAs('excels', $file_name, 'local');

//             $data = Excel::toArray([], storage_path('app/private/' . $filePath));

//             if (empty($data) || empty($data[0])) {
//                 return response()->json([
//                     'status' => "fail",
//                     'message' => 'The uploaded file is empty or invalid.',
//                 ], 422);
//             }

//             $sheetData = $data[0];
//             $headers = array_filter($sheetData[0]);

//             if (empty($headers)) {
//                 return response()->json([
//                     'status' => "fail",
//                     'message' => 'No headers found in the uploaded file.',
//                 ], 422);
//             }

//             $rowFrom = $request->row_from;
//             $rowTo = $request->row_to;

//             if ($rowFrom < 1 || $rowTo < 1 || $rowFrom > count($sheetData) || $rowTo > count($sheetData)) {
//                 return response()->json([
//                     'status' => "fail",
//                     'message' => 'Invalid row range.',
//                 ], 422);
//             }

//             $rowFrom = $rowFrom - 1;
//             $rowTo = $rowTo - 1; 

//             $sheetData = array_slice($sheetData, $rowFrom, $rowTo - $rowFrom + 1);

//             $jsonRows = []; 

//             foreach ($sheetData as $index => $row) {
//                 $rowData = [];
//                 foreach ($headers as $colIndex => $header) {
//                     $rowData["column " . ($colIndex + 1)] = $row[$colIndex] ?? null;
//                 }

//                 $jsonRows[] = $rowData;
//             }

//             $excel_data = BulkUploadExcel::create([
//                 'upload_file' => $filePath,
//                 'category' => $request->category,
//                 'sector_category' => $request->sector_category,
//                 'file_path' => $request->file_path,
//                 'extension' => $request->extension,
//                 'row_from' => $request->row_from,
//                 'row_to' => $request->row_to,
//                 'storage' => $request->ftp_account,
//                 'data' => json_encode($jsonRows),
//             ]);

//             $columns = array_map(function ($index) {
//                 return 'column ' . ($index + 1);
//             }, array_keys($headers));

//             return response()->json([
//                 'status' => "success",
//                 'message' => 'Documents added successfully.',
//                 'columns' => $columns, 
//                 'excel_id' => $excel_data->id,
//                 'attributes' => $attributes, 
//             ], 201);

//         } catch (\Exception $e) {
//             return response()->json([
//                 'status' => "fail",
//                 'message' => 'Request failed',
//                 'error' => $e->getMessage()
//             ], 500);
//         }
//     }
// }
public function excel_bulk_upload(Request $request)
{
    if ($request->isMethod('get')) {
        $documents = BulkUploadExcel::orderBy('id', 'DESC')->get();

        return response()->json([
            'status' => "success",
            'documents' => $documents
        ], 200);
    }

    if ($request->isMethod('post')) {
        try {
            $validator = Validator::make($request->all(), [
                'upload_file' => 'required',
                'category' => 'required',
                'sector_category' => 'required',
                // 'file_path' => 'required',
                // 'ftp_account' => 'required',
                // 'extension' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->upload_file;
            $data = Excel::toArray([], $file);
            $rows = $data[0];
            $header = array_shift($rows); 
            $preview_extension = CompanyProfile::first()->preview_file_extension ?? 'png';
            if ($request->copy_files_from_computer == 1) {
                $ftp_account = Categories::where('id', $request->category)->value('ftp_account');

                if ($ftp_account == null || $ftp_account == '') {
                    if (FTPAccounts::where("is_default", 1)->exists()) {
                        $ftp_account_details = FTPAccounts::where("is_default", 1)->first();
                        $file_path = $ftp_account_details->root_path;
                        $ftp_account = $ftp_account_details->id;
                    } else {
                        $ftp_account_details = FTPAccounts::first();
                        $file_path = $ftp_account_details->root_path;
                        $ftp_account = $ftp_account_details->id;
                    }
                } else {
                    $ftp_account_details = FTPAccounts::where("id", $ftp_account)->first();
                    $file_path = $ftp_account_details->root_path;
                }

                if (!$ftp_account_details || !$ftp_account) {
                    return response()->json([
                        'status' => "fail",
                        'message' => "No FTP account configured for this category."
                    ], 404);
                }

                $ftp_host = $ftp_account_details->host;
                $ftp_username = $ftp_account_details->username;
                $ftp_password = $ftp_account_details->password;
                $ftp_root = $ftp_account_details->root_path;
                $ftp_port = $ftp_account_details->port;

                if (substr($ftp_root, -1) !== '/') {
                    $ftp_root .= '/';
                }

                config([
                    'filesystems.disks.dynamic_ftp' => [
                        'driver' => 'ftp',
                        'host' => $ftp_host,
                        'username' => $ftp_username,
                        'password' => $ftp_password,
                        'port' => (int) $ftp_port,
                        'root' => $ftp_root,
                    ],
                ]);

                $uploadedFile = $request->file('document');
                $fileName = $uploadedFile->getClientOriginalName();
                $file_extension = $uploadedFile->getClientOriginalExtension();

                $validNames = [];
                foreach ($rows as $row) {
                    $record = array_combine($header, $row);
                    if (!empty($record['name'])) {
                        $validNames[] = pathinfo($record['name'], PATHINFO_FILENAME);
                    }
                }
                $processed = false;
                if (str_ends_with($fileName, '_preview.'.$preview_extension)) {
                    $baseFileName = str_replace('_preview.'.$preview_extension, '', $fileName);
                    
                    if (in_array($baseFileName, $validNames)) {
                        $uploadedFile->move(public_path('uploads/document_previews'), $fileName);
                        $processed = true;
                    }
                   
                }
                else{
                // $files_extension = ltrim($request->extension, '.');
                foreach ($rows as $row) {
                    $record = array_combine($header, $row);
                    // $expectedFileName = ($record['name'] ?? '') . '.' . $files_extension;
                    $expectedFileName = ($record['name'] ?? '');

                    if ($fileName === $expectedFileName) {
                        // $db_file_name = time() . '-' . Str::uuid()->toString() . '.' . $files_extension;
                        $db_file_name = time() . '-' . Str::uuid()->toString() . '.' . $file_extension;
                        $ftp_file_path = $ftp_root . $db_file_name;
                        $preview_name = pathinfo($record['name'], PATHINFO_FILENAME). '_preview.'.$preview_extension;
                        $stream = fopen($uploadedFile->getRealPath(), 'r+');
                        if (!Storage::disk('dynamic_ftp')->put($db_file_name, $stream)) {
                            fclose($stream);
                            return response()->json([
                                'status' => "fail",
                                'message' => "Failed to upload file: $expectedFileName to the FTP server."
                            ], 500);
                        }
                        fclose($stream);

                        $document = new Documents();

                        $user = auth('api')->user();
                        $roles = json_decode($user->role, true);

                        $roleId = $roles[0] ?? null;
                        $role = $roleId ? Roles::find($roleId) : null;

                        $is_approved = ($role && $role->needs_approval == 1) ? 0 : 1;

                        $document->name = $record['name'] ?? null;
                        // $document->type = $files_extension;
                        $document->type = $file_extension;
                        $document->category = $request->category;
                        $document->sector_category = $request->sector_category;
                        $document->description = $record['description'] ?? null;
                        if (!empty($record['meta_tags'])) {
                            $metaTagsArray = array_map('trim', explode(',', $record['meta_tags']));
                            $document->meta_tags = json_encode($metaTagsArray);
                        } else {
                            $document->meta_tags = json_encode([]);
                        }
                        $document->file_path = $ftp_file_path;
                        $document->document_preview = 'uploads/document_previews/' . $preview_name;
                        $document->uploaded_method = 'ftp';
                        $document->storage = $ftp_account;

                        $attributeData = [];
                        $attributes = Attribute::where('category', $request->category)->value('attributes');
                        if ($attributes) {
                            $attributes = json_decode($attributes, true);
                            foreach ($attributes as $attribute) {
                                if (isset($record[$attribute])) {
                                    $value = $record[$attribute];
                        
                                    // Check if the value is purely numeric and falls within a valid Excel date range
                                    if (is_numeric($value) && $value >= 1 && $value <= 2958465) {
                                        try {
                                            $excelDate = Date::excelToDateTimeObject((int)$value);
                                            if ($excelDate instanceof DateTime) {
                                                $value = Carbon::instance($excelDate)->format('Y-m-d');
                                            }
                                        } catch (Exception $e) {
                                            // If conversion fails, keep the original value
                                        }
                                    } 
                                    // Ensure value is not alphanumeric before checking strtotime
                                    elseif (is_string($value) && preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
                                        // Convert only valid date strings like '2024-03-14'
                                        $value = Carbon::parse($value)->format('Y-m-d');
                                    }
                        
                                    $attributeData[] = [
                                        'value' => $value,
                                        'attribute' => $attribute,
                                    ];
                                }
                            }
                        }
                        $document->attributes = !empty($attributeData) ? json_encode($attributeData) : null;
                        $document->indexed_or_encrypted = 'no';
                         $document->is_approved = $is_approved;
                        $document->save();

                        $date_time = Carbon::now()->format('Y-m-d H:i:s');
                        $auditFunction = new CommonFunctionsController();
                        $auditFunction->document_audit_trail('document added','document', $request->user, $document->id, $date_time, null, null);

                        $version = new DocumentVersions();
                        $version->document_id = $document->id;
                        // $version->type = $files_extension;
                        $version->type = $file_extension;
                        $version->file_path = $ftp_file_path;
                        $version->date_time = $date_time;
                        $version->user = $request->user;
                        $version->save();

                        $processed = true;
                        break;
                    }
                    else{

                    }
                }
                }
                if ($processed) {
                    return response()->json([
                        'status' => "success",
                        'message' => 'File uploaded to FTP server and processed successfully.'
                    ], 201);
                } else {
                    return response()->json([
                        'status' => "fail",
                        'message' => "The uploaded file does not match any entry in the Excel column 'name'."
                    ], 422);
                }
            }
            else{
                $ftp_account = Categories::where('id',$request->category)->value('ftp_account');
                $file_path = '';
                if($ftp_account == null || $ftp_account == ''){
                    if(FTPAccounts::where("is_default", 1)->exists()){
                        $ftp_account_details = FTPAccounts::where("is_default", 1)->first();
                        $file_path = $ftp_account_details->root_path;
                        $ftp_account = $ftp_account_details->id;
                    }
                    else{
                        $ftp_account_details = FTPAccounts::first();
                        $file_path = $ftp_account_details->root_path;
                        $ftp_account = $ftp_account_details->id;
                    }
                }
                else{
                    $ftp_account_details = FTPAccounts::where("id",$ftp_account)->first();
                    $file_path = $ftp_account_details->root_path;
                }
                if (substr($file_path, -1) !== '/') {
                    $file_path .= '/';
                }
               
                $ftp_host = $ftp_account_details->host;
                $ftp_username = $ftp_account_details->username;
                $ftp_password = $ftp_account_details->password;
                $ftp_root = $file_path;
                $ftp_port = $ftp_account_details->ftp_port;


                config([
                    'filesystems.disks.dynamic_ftp' => [
                        'driver' => 'ftp',
                        'host' => $ftp_host,
                        'username' => $ftp_username,
                        'password' => $ftp_password,
                        'port' => (int)$ftp_port,
                        'root' => $file_path,
                    ],
                ]);
           
                $attributes = Attribute::where('category', $request->category)->value('attributes');
                if ($attributes) {
                    $attributes = json_decode($attributes, true); 
                } else {
                    $attributes = []; 
                }
                // $files_extension = ltrim($request->extension, '.');
                foreach ($rows as $row) {

                    $record = array_combine($header, $row);
                    $filePath = $file_path .pathinfo($record['name'], PATHINFO_FILENAME) . '_preview.'.$preview_extension;
                    $disk = Storage::disk('dynamic_ftp');

                    if ($disk->exists($filePath)) {
                        $fileContents = $disk->get($filePath);
                    
                        $destinationPath = public_path('uploads/document_previews/' . pathinfo($record['name'], PATHINFO_FILENAME) . '_preview.'.$preview_extension);
            
                        if (!file_exists(public_path('uploads/document_previews'))) {
                            mkdir(public_path('uploads/document_previews'), 0755, true);
                        }
                    
                        file_put_contents($destinationPath, $fileContents);
                        
                    }
                    
                    $user = auth('api')->user();
                    $roles = json_decode($user->role, true);

                    $roleId = $roles[0] ?? null;
                    $role = $roleId ? Roles::find($roleId) : null;

                    $is_approved = ($role && $role->needs_approval == 1) ? 0 : 1;
                    
                    $document = new Documents();
                    $document->name = $record['name'] ?? null;
                    $document->type =  $record['name'] ? pathinfo($record['name'], PATHINFO_EXTENSION) : null;
                    $document->category = $request->category;
                    $document->sector_category = $request->sector_category;
                    $document->description = $record['description'] ?? null;
                    if (!empty($record['meta_tags'])) {
                        $metaTagsArray = array_map('trim', explode(',', $record['meta_tags']));
                        $document->meta_tags = json_encode($metaTagsArray);
                    } else {
                        $document->meta_tags = json_encode([]);
                    }
                    // $document->file_path = $file_path . $record['name'] . '.' . $files_extension;
                    $document->file_path = $file_path . $record['name'];
                    $document->document_preview = 'uploads/document_previews/' . pathinfo($record['name'], PATHINFO_FILENAME) . '_preview.'.$preview_extension;
                    $document->uploaded_method = 'ftp';
                    $document->storage = $ftp_account;
    
                    $attributeData = [];
                    // if (!empty($attributes)) {
                    //     foreach ($attributes as $attribute) {
                    //         if (isset($record[$attribute])) {
                    //             $attributeData[] = [
                    //                 'value' => $record[$attribute],
                    //                 'attribute' => $attribute
                    //             ];
                    //         }
                    //     }
                    // }
                    if ($attributes) {
                        $attributes = json_decode($attributes, true);
                        foreach ($attributes as $attribute) {
                            if (isset($record[$attribute])) {
                                $value = $record[$attribute];
                    
                                // Check if the value is purely numeric and falls within a valid Excel date range
                                if (is_numeric($value) && $value >= 1 && $value <= 2958465) {
                                    try {
                                        $excelDate = Date::excelToDateTimeObject((int)$value);
                                        if ($excelDate instanceof DateTime) {
                                            $value = Carbon::instance($excelDate)->format('Y-m-d');
                                        }
                                    } catch (Exception $e) {
                                        // If conversion fails, keep the original value
                                    }
                                } 
                                // Ensure value is not alphanumeric before checking strtotime
                                elseif (is_string($value) && preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
                                    // Convert only valid date strings like '2024-03-14'
                                    $value = Carbon::parse($value)->format('Y-m-d');
                                }
                    
                                $attributeData[] = [
                                    'value' => $value,
                                    'attribute' => $attribute,
                                ];
                            }
                        }
                    }
    
                    $document->attributes = !empty($attributeData) ? json_encode($attributeData) : null;
                    $document->indexed_or_encrypted = 'no';
                     $document->is_approved = $is_approved;
                    $document->save();
    
                    $date_time = Carbon::now()->format('Y-m-d H:i:s');
                    $auditFunction = new CommonFunctionsController();
                    $auditFunction->document_audit_trail('document added','document', $request->user, $document->id, $date_time, null, null);
    
                    $version = new DocumentVersions();
                    $version->document_id = $document->id;
                    $version->type =  $record['name'] ? pathinfo($record['name'], PATHINFO_EXTENSION) : null;
                    // $version->file_path = $request->file_path . $record['name'] . '.' . $files_extension;
                    $version->file_path = $request->file_path . $record['name'];
                    $version->date_time = $date_time;
                    $version->user = $request->user;
                    $version->save();
                }
    
                return response()->json([
                    'status' => "success",
                    'message' => 'Documents added successfully.'
                ], 201);
            }
            

        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}


public function excel_bulk_upload_confirm(Request $request)
{
    if ($request->isMethod('post')) {
        try {
            // Update validation to allow nullable fields
            $validator = Validator::make($request->all(), [
                'column_for_name' => 'required',
                'column_for_description' => 'nullable',  // Allow null
                'column_for_meta_tags' => 'nullable',     // Allow null
                'column_for_attributes' => 'nullable|json',  // Allow null or JSON format
                'excel_id' => 'required|exists:bulk_uploads_excel,id',  // Ensure excel_id exists
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get the uploaded excel data from the BulkUploadExcel table (using the data column)
            $excel_file_details = BulkUploadExcel::where('id', $request->excel_id)->first();

            // Get the stored JSON data from the 'data' column
            $rows = json_decode($excel_file_details->data, true);

            if (empty($rows)) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'No data found in the file.',
                ], 422);
            }

            // Get the user-selected columns
            $column_for_name = $request->column_for_name;
            $column_for_description = $request->column_for_description; // Can be null
            $column_for_meta_tags = $request->column_for_meta_tags; // Can be null
            $column_for_attributes = $request->column_for_attributes ? json_decode($request->column_for_attributes, true) : []; // Can be null

            // Iterate over the Excel rows and prepare the data for insertion into another table
            foreach ($rows as $row) {
                // Map the selected columns from the row
                $name = $row[$column_for_name] ?? null;
                $description = $column_for_description ? ($row[$column_for_description] ?? null) : null;
                $meta_tags = $column_for_meta_tags ? ($row[$column_for_meta_tags] ?? null) : null;

                // Extract attributes based on the user mapping (attributes in the Excel row)
                $attributes = [];
                foreach ($column_for_attributes as $attribute_mapping) {
                    $column_value = $row[$attribute_mapping['column']] ?? null;
                    $attributes[] = [
                        'attribute' => $attribute_mapping['attribute'],
                        'value' => $column_value
                    ];
                }
            
                if (substr($excel_file_details->file_path, -1) !== '/') {
                    $excel_file_details->file_path .= '/';
                }
                // Insert the data into another table (for example, IndividualUploadData)
                BulkUploadExcelConfirmed::create([
                    'excel_id' => $request->excel_id,
                    'name' => $name,
                    'type' => $excel_file_details->extension,
                    'category' => $excel_file_details->category,
                    'sector_category' => $excel_file_details->sector_category,
                    'storage' => $excel_file_details->storage,
                    'description' => $description,
                    'meta_tags' => $meta_tags, 
                    'file_path' =>$excel_file_details->file_path.$name.'.'.$excel_file_details->extension, 
                    'attributes' => json_encode($attributes), 
                ]);
            }
            BulkUploadExcel::where('id', $request->excel_id)->delete();
            // Respond with success message

            $documents = BulkUploadExcelConfirmed::select('id', 'name', 'type', 'storage', 'category')->orderBy('id', 'DESC')
                ->with(['category' => function ($query) {
                    $query->select('id', 'category_name');
                }])
                ->get();
                foreach($documents as $document){
                    $ftp_name = FTPAccounts::where('id', $document->storage)->value('name');
                    $document->storage = $ftp_name ?? 'Unknown FTP Storage';
                }
            return response()->json([
                'status' => "success",
                'message' => 'Documents added successfully.',
                'documents' => $documents,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

public function save_bulk_document_excel($id,Request $request)
{

    try {
        if($request->isMethod('get')){
            $excel_file_details =  BulkUploadExcelConfirmed::where('id', $id)->select('id', 'name', 'category', 'sector_category', 'description', 'meta_tags','attributes')
            ->with(['category' => function ($query) {
                $query->select('id', 'category_name');
            }])
            ->with(['sector' => function ($query) {
                $query->select('id', 'sector_name');
            }])
            ->get();
            return response()->json($excel_file_details);
        }
        if($request->isMethod('post')){
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "fail",
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

            $excel_file_details = BulkUploadExcelConfirmed::where('id', $id)->first();
            $user = auth('api')->user();
            $roles = json_decode($user->role, true);

            $roleId = $roles[0] ?? null;
            $role = $roleId ? Roles::find($roleId) : null;

            $is_approved = ($role && $role->needs_approval == 1) ? 0 : 1;
            $document = new Documents();
            $document->name = $request->name;
            $document->type = $excel_file_details->type;
            $document->category = $excel_file_details->category;
            $document->sector_category = $excel_file_details->sector_category;
            $document->description = $request->description; 
            $document->meta_tags = $request->meta_tags;
            $document->file_path = $excel_file_details->file_path;
            $document->uploaded_method = 'ftp';
            $document->attributes = $request->attribute_data;
            $document->storage = $excel_file_details->storage;
             $document->is_approved = $is_approved;
            $document->save();
            
            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document added','document', $request->user, $document->id, $date_time, $request->assigned_roles, $request->assigned_users);
            
            $version = new DocumentVersions();
            $version->document_id = $document->id;
            $version->file_path = $excel_file_details->file_path;
            $version->date_time = $date_time;
            $version->user = $request->user;
            $version->save();

        if ($request->assigned_roles) {
            foreach (json_decode($request->assigned_roles, true) as $assigned_role) {
                $shared_role = new DocumentSharedRoles();
                $shared_role->document_id = $document->id;
                $shared_role->role = $assigned_role;
                $shared_role->is_time_limited = $request->role_is_time_limited;
                $shared_role->start_date_time = $request->role_start_date_time;
                $shared_role->end_date_time = $request->role_end_date_time;
                $shared_role->is_downloadable = $request->role_is_downloadable;
                $shared_role->save();
            }
        }

        if ($request->assigned_users) {
            foreach (json_decode($request->assigned_users, true) as $assigned_user) {
                $shared_user = new DocumentSharedUsers();
                $shared_user->document_id = $document->id;
                $shared_user->user = $assigned_user;
                $shared_user->is_time_limited = $request->user_is_time_limited;
                $shared_user->start_date_time = $request->user_start_date_time;
                $shared_user->end_date_time = $request->user_end_date_time;
                $shared_user->is_downloadable = $request->user_is_downloadable;
                $shared_user->save();
            }
        }

        BulkUploadExcelConfirmed::where('id', $id)->delete();

        return response()->json([
            'status' => "success",
            'message' => 'Document added.'
        ], 201);
    }

    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function save_bulk_document_excel_bulk(Request $request)
{

    try {

        if($request->isMethod('post')){
        $validator = Validator::make($request->all(), [
            'excel_id' => 'required',
            'user' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "fail",
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

            $confiremd_files = BulkUploadExcelConfirmed::where('excel_id', $request->excel_id)->get();
            $user = auth('api')->user();
            $roles = json_decode($user->role, true);

            $roleId = $roles[0] ?? null;
            $role = $roleId ? Roles::find($roleId) : null;

            $is_approved = ($role && $role->needs_approval == 1) ? 0 : 1;
            foreach($confiremd_files as $confiremd_file){
                $document = new Documents();
                $document->name = $confiremd_file->name;
                $document->type = $confiremd_file->type;
                $document->category = $confiremd_file->category;
                $document->sector_category = $confiremd_file->sector_category;
                $document->description = $confiremd_file->description; 

                $document->file_path = $confiremd_file->file_path;
                $document->uploaded_method = 'ftp';
                $document->attributes = $confiremd_file->attributes;
                $document->storage = $confiremd_file->storage;
                 $document->is_approved = $is_approved;
                $document->save();
                
                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document added','document', $request->user, $document->id, $date_time, null, null);
                
                $version = new DocumentVersions();
                $version->document_id = $document->id;
                $version->file_path = $confiremd_file->file_path;
                $version->date_time = $date_time;
                $version->user = $request->user;
                $version->save();

                BulkUploadExcelConfirmed::where('id', $confiremd_file->id)->delete();
            }

        return response()->json([
            'status' => "success",
            'message' => 'Documents added.'
        ], 201);
    }

    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function bulk_upload_excel_delete_record($id,Request $request)
{
     
    try {
        BulkUploadExcelConfirmed::where('id', '=', $id)->delete();
            return response()->json([
                'status' => "success",
                'message' => 'Record Deleted'
            ], 201);
        
    } catch (\Exception $e) {

        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }    
}
public function bulk_upload_excel_delete_file($id,Request $request)
{
     
    try {
        BulkUploadExcel::where('id', '=', $id)->delete();
            return response()->json([
                'status' => "success",
                'message' => 'File Deleted'
            ], 201);
        
    } catch (\Exception $e) {

        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }    
}

public function process_documents(Request $request)
{
    $errors = [];

    try {
        $documents = Documents::where('indexed_or_encrypted', '!=', 'yes')->get();

        foreach ($documents as $document) {
            try {
                if ($document->uploaded_method == 'ftp') {
                    $ftp_account = Categories::where('id', $document->category)->value('ftp_account');

                    if ($ftp_account == null || $ftp_account == '') {
                        if (FTPAccounts::where("is_default", 1)->exists()) {
                            $ftp_account_details = FTPAccounts::where("is_default", 1)->first();
                        } else {
                            $ftp_account_details = FTPAccounts::first();
                        }
                    } else {
                        $ftp_account_details = FTPAccounts::where("id", $ftp_account)->first();
                    }

                    if (!$ftp_account_details) {
                        throw new \Exception("No FTP account configured for this category.");
                    }

                    $ftp_host = $ftp_account_details->host;
                    $ftp_username = $ftp_account_details->username;
                    $ftp_password = $ftp_account_details->password;
                    $ftp_root = rtrim($ftp_account_details->root_path, '/') . '/';
                    $ftp_port = $ftp_account_details->port;

                    config([
                        'filesystems.disks.dynamic_ftp' => [
                            'driver' => 'ftp',
                            'host' => $ftp_host,
                            'username' => $ftp_username,
                            'password' => $ftp_password,
                            'port' => (int) $ftp_port,
                            'root' => $ftp_root,
                        ],
                    ]);

                    $disk = Storage::disk('dynamic_ftp');
                    $filePath = $document->file_path;

                    $localDisk = Storage::disk('local');
                    $localDisk->put($filePath, $disk->get($filePath));

                    $final_file_path = $filePath;
                } else {
                    $final_file_path = $document->file_path;
                }

                $file_path_full = storage_path("app/private/$final_file_path");

                // $getFileTextContent = new CommonFunctionsController();
                // $content = $getFileTextContent->sendToNode($file_path_full);

                $response = Http::attach(
                    'file', file_get_contents($file_path_full), basename($file_path_full)
                )->post('http://127.0.0.1:8001/extract');

                $content = $response->json()['pages'] ?? [];

                $indexFileTextContent = new CommonFunctionsController();
                $indexFileTextContent->indexDocumentContent($document->id, $content);

                $document->indexed_or_encrypted = 'yes';
                $document->update();
            } catch (\Exception $e) {
                $errors[] = [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ];
                continue;
            }
        }

        return response()->json([
            'status' => count($errors) ? "partial_success" : "success",
            'message' => count($errors) ? 'Some documents failed to process.' : 'All documents processed successfully.',
            'errors' => $errors
        ], count($errors) ? 207 : 201); 
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function generate_excel_with_file_names(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'category' => 'required',
            'documents' => 'required|array',
            'documents.*' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "fail",
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }


        $headers = ['name', 'description', 'meta_tags'];


        $attributeRecord = Attribute::where('category', $request->category)->select('attributes')->first();
        
        if ($attributeRecord) {
            $attributeData = json_decode($attributeRecord->attributes, true);
            
            if (is_array($attributeData)) {
                foreach ($attributeData as $value) {
                    if (is_string($value)) {
                        $headers[] = $value;
                    }
                }
            }
        }

        $rows = [];
        if ($request->has('documents')) {
            foreach ($request->input('documents') as $fileName) {
                if (strpos($fileName, '_preview') !== false) {
                    continue;
                }

                $rows[] = array_merge(
                    [$fileName, '', ''],
                    array_fill(0, count($headers) - 3, '') 
                );
            }
        }


        $export = new class($headers, $rows) implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings {
            private $rows;
            private $headers;

            public function __construct(array $headers, array $rows)
            {
                $this->headers = $headers;
                $this->rows = $rows;
            }

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headers;
            }
        };

        $fileName = 'category_template_' . time() . '.xlsx';

        $excelData = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        $base64Excel = base64_encode($excelData);

        return response()->json([
            'status' => 'success',
            'message' => 'Excel file generated.',
            'file_name' => $fileName,
            'file_data' => $base64Excel,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

}

