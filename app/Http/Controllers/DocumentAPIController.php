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
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Typography\FontFactory;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use MeiliSearch\Client;

use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Roles;
use App\Models\Documents;
use App\Models\Sectors;
use App\Models\DocumentSharedRoles;
use App\Models\DocumentSharedUsers;
use App\Models\DocumentSharedLinks;
use App\Models\DocumentVersions;
use App\Models\DocumentComments;
use App\Models\Reminders;
use App\Models\FTPAccounts;
use App\Models\SMTPDetails;
use App\Models\MongoDocuments;

use App\Http\Controllers\CommonFunctionsController;
use App\Models\DocumentAuditTrial;
use App\Services\TikaService;
use Mail;

use function Laravel\Prompts\info;

class DocumentAPIController extends Controller
{


    public function add_document(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document' => 'required|file',
                // 'document_preview' => 'required|file',
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


            $file_name = time() . '-' . Str::uuid()->toString() . '.' . $request->document->getClientOriginalExtension();
            $filePath = $request->document->storeAs('documents', $file_name, 'local');

            if ($request->document_preview == null) {
                $preview_name = 'default.png';
            } else {
                $preview_name = time() . '-' . Str::uuid()->toString() . '.' . $request->document_preview->extension();
                $request->document_preview->move(public_path('uploads/document_previews'), $preview_name);
            }


            $file_extension = $request->document->getClientOriginalExtension();

            $file_path_full = storage_path("app/private/$filePath");

            // $getFileTextContent = new CommonFunctionsController();
            // $content = $getFileTextContent->sendToNode($file_path_full);

            $response = Http::attach(
                'file',
                file_get_contents($file_path_full),
                $request->document->getClientOriginalName()
            )->post('http://127.0.0.1:8001/extract');

            $content = $response->json()['pages'] ?? [];

            // return response()->json([
            //     'status' => "success",
            //     'content' => $content
            // ], 201);
            $user = auth('api')->user();
            $roles = json_decode($user->role, true);

            $roleId = $roles[0] ?? null;
            $role = $roleId ? Roles::find($roleId) : null;

            $is_approved = ($role && $role->needs_approval == 1) ? 0 : 1;

            $document = new Documents();
            $document->name = $request->name;
            $document->type = $file_extension;
            $document->category = $request->category;
            $document->sector_category = $request->sector_category;
            $document->storage = $request->storage;
            $document->description = $request->description;
            $document->meta_tags = $request->meta_tags;
            $document->document_preview = 'uploads/document_previews/' . $preview_name;
            $document->file_path = $filePath;
            $document->uploaded_method = 'direct';
            $document->attributes = $request->attribute_data;
            $document->expiration_date = $request->expiration_date;
            $document->indexed_or_encrypted = 'yes';
            // $document->is_approved = $is_approved;
            $document->is_approved = 0; // all documents need approval

            $category = Categories::find($request->category);
            $approvers = $category->approver_ids ? json_decode($category->approver_ids, true) : [];

            foreach ($approvers as $key => $item) {
                $approvers[$key]['is_accepted'] = 0;
            }
            $document->approver_ids = json_encode($approvers);
            info('Approver IDs: ' . $document->approver_ids);
            $document->save();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document added', 'document', $request->user, $document->id, $date_time, $request->assigned_roles, $request->assigned_users);

            $version = new DocumentVersions();
            $version->document_id = $document->id;
            $version->type = $file_extension;
            $document->document_preview = 'uploads/document_previews/' . $preview_name;
            $version->file_path = $filePath;
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

            $indexFileTextContent = new CommonFunctionsController();
            $indexFileTextContent->indexDocumentContent($document->id, $content);

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
    public function edit_document($id, Request $request)
    {




        try {
            // if ($request->isMethod('get')) {
            //     $document =  Documents::where('id', $id)->select('id', 'name', 'category', 'description', 'meta_tags')
            //         ->with(['category' => function ($query) {
            //             $query->select('id', 'category_name', 'approver_ids');
            //         }])
            //         ->get();

            //     info('document: ' . json_encode($document));



            //     return response()->json($document);
            // }




            if ($request->isMethod('get')) {

                $document = Documents::where('id', $id)
                    ->select('id', 'name', 'category', 'description', 'meta_tags', 'approver_ids')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                    ->first();

                info('document: ' . json_encode($document));

                // Read approver_ids JSON
                $approverJson = $document->approver_ids;
                info('approverJson: ' . $approverJson);

                // If no approvers found
                if (!$approverJson) {
                    $document->all_approvers_accepted = false;
                    return response()->json($document);
                }

                // Convert JSON into array
                $approvers = json_decode($approverJson, true);

                // If JSON is not valid
                if (!is_array($approvers)) {
                    $document->all_approvers_accepted = false;
                    return response()->json($document);
                }

                // Check whether all "is_accepted" values are 1
                $allAccepted = true;

                foreach ($approvers as $item) {
                    if (!isset($item['is_accepted']) || $item['is_accepted'] != 1) {
                        $allAccepted = false;
                        break;
                    }
                }

                $document->all_approvers_accepted = $allAccepted;

                info('all_approvers_accepted: ' . ($allAccepted ? 'true' : 'false'));

                return response()->json($document);
            }



            if ($request->isMethod('post')) {

                $validator = Validator::make($request->all(), [
                    'name' => 'required',
                    'category' => 'required'
                ]);


                if ($validator->fails()) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }


                $document =  Documents::where('id', '=', $id)->first();
                $document->name = $request->name;
                $document->category = $request->category;
                $document->description = $request->description;
                $document->meta_tags = $request->meta_tags;
                $category = Categories::find($request->category);
                $approvers = $category->approver_ids ? json_decode($category->approver_ids, true) : [];

                foreach ($approvers as $key => $item) {
                    $approvers[$key]['is_accepted'] = 0;
                }
                $document->approver_ids = json_encode($approvers);
                info('Approver IDs edit: ' . $document->approver_ids);

                $document->update();

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document basic details edited', 'document', $request->user, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Document updated'
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



    public function documents(Request $request)
    {
        try {
            if ($request->isMethod('get')) {

                // Get all approved, non-archived documents
                $documents = Documents::where(function ($query) {
                    $query->where('is_archived', 0)
                        ->orWhereNull('is_archived');
                })
                    ->where('is_approved', 1)
                    ->where('indexed_or_encrypted', 'yes')
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
                    ->orderBy('id', 'DESC')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                    ->get();

                info('total documents: ' . $documents->count());

                // Get all relevant audit records at once
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documents->pluck('id'))
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                // Get all relevant users at once
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Map data to documents
                foreach ($documents as $document) {
                    $created_data = $auditData->firstWhere('changed_source', $document->id);

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // Handle FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Handle document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function unapproved_documents(Request $request)
    {
        try {
            if ($request->isMethod('get')) {

                // get logged user's id and role
                $userId = (string) auth('api')->id();
                $userRole = auth('api')->user()->role ?? null;

                info("Logged user ID: $userId");
                info("Logged user Role: $userRole");

                // get all documents with category
                $documents = Documents::with('category')
                    ->orderBy('id', 'DESC')
                    ->get();

                info("Total documents fetched: " . $documents->count());

                // filter only by userId and userRole
                $filtered = $documents->filter(function ($document) use ($userId, $userRole) {
                    info("Filtering document ID: " . $document->id);

                    // protect against missing category
                    $category = $document->category()->first();
                    if (!$category) {
                        info("No category for document ID: " . $document->id);
                        return false;
                    }

                    $approvalType = $category->approval_type;
                    info("Approval type for document ID " . $document->id . ": " . $approvalType);

                    // decode approver list
                    $approverList = json_decode($document->approver_ids, true);
                    if (!$approverList || !is_array($approverList)) {
                        info("Approver list missing or invalid for document ID: " . $document->id);
                        return false;
                    }

                    info('approval list: ' . json_encode($approverList));

                    // find the matching approver entry for this logged user
                    $userApprover = null;
                    foreach ($approverList as $item) {
                        // for users approval_type match by user id
                        if ($approvalType === 'users' && (string)$item['id'] === $userId) {
                            $userApprover = $item;
                            break;
                        }

                        // for roles approval_type match by role (item['id'] is expected to hold role name)
                        if ($approvalType === 'roles' && $userRole !== null && (string)$item['id'] === (string)$userRole) {
                            $userApprover = $item;
                            break;
                        }
                    }

                    // no matching entry -> don't show
                    if (!$userApprover) {
                        info("No matching approver entry for user/role on document ID: " . $document->id);
                        return false;
                    }

                    // if this user/role already accepted -> don't show
                    if (isset($userApprover['is_accepted']) && (int)$userApprover['is_accepted'] === 1) {
                        info("User/role already accepted for document ID: " . $document->id . ". Hiding.");
                        return false;
                    }

                    // check level rules
                    $level = isset($userApprover['level']) ? (int)$userApprover['level'] : 0;

                    // level 1 -> allowed
                    if ($level === 1) {
                        info("User/role at level 1 for document ID: " . $document->id . ". Showing.");
                        return true;
                    }

                    // level > 1 -> need previous level to exist and be accepted
                    $previousLevel = $level - 1;
                    $previousApprover = null;
                    foreach ($approverList as $item) {
                        if (isset($item['level']) && (int)$item['level'] === $previousLevel) {
                            $previousApprover = $item;
                            break;
                        }
                    }

                    if (!$previousApprover) {
                        info("Previous level ($previousLevel) not found for document ID: " . $document->id . ". Hiding.");
                        return false;
                    }

                    if (isset($previousApprover['is_accepted']) && (int)$previousApprover['is_accepted'] === 1) {
                        info("Previous level ($previousLevel) accepted for document ID: " . $document->id . ". Showing.");
                        return true;
                    }

                    info("Previous level ($previousLevel) not accepted for document ID: " . $document->id . ". Hiding.");
                    return false;
                });


                info("Total documents after filter: " . $filtered->count());

                // format output
                foreach ($filtered as $document) {
                    $document->category_name = $document->category->category_name ?? '';
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $filtered->values()
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }






    // old function for unapproved documents
    // public function unapproved_documents(Request $request)
    // {
    //     try {
    //         if ($request->isMethod('get')) {

    //             $userId = (string) auth('api')->id();

    //             // Get IDs of users supervised by the current user
    //             $supervisedUserIds = User::whereJsonContains('supervisors', $userId)
    //                 ->pluck('id');

    //             // Get document IDs and creator info in one query
    //             $auditData = DocumentAuditTrial::whereIn('user', $supervisedUserIds)
    //                 ->where('operation', 'document added')
    //                 ->get(['changed_source', 'user', 'date_time']);

    //             $documentIds = $auditData->pluck('changed_source');

    //             // Load documents
    //             $documents = Documents::where(function ($query) {
    //                 $query->whereNull('is_approved')
    //                     ->orWhere('is_approved', 0);
    //             })
    //                 ->where('indexed_or_encrypted', 'yes')
    //                 ->whereIn('id', $documentIds)
    //                 ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
    //                 ->orderBy('id', 'DESC')
    //                 ->with(['category' => function ($query) {
    //                     $query->select('id', 'category_name');
    //                 }])
    //                 ->get();

    //             // Get all relevant users at once
    //             $userIds = $auditData->pluck('user')->unique();
    //             $users = User::with('userDetails')->whereIn('id', $userIds)->get()->keyBy('id');

    //             $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
    //             $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

    //             // Map document data
    //             foreach ($documents as $document) {

    //                 // Find audit record for this document
    //                 $created_data = $auditData->firstWhere('changed_source', $document->id);

    //                 if ($created_data && isset($users[$created_data->user])) {
    //                     $userDetails = $users[$created_data->user]->userDetails;
    //                     $created_by = $userDetails ? $userDetails->first_name . ' ' . $userDetails->last_name : 'Unknown User';
    //                     $created_date = $created_data->date_time;
    //                 } else {
    //                     $created_by = 'Unknown User';
    //                     $created_date = 'Unknown Date';
    //                 }

    //                 $document->created_by = $created_by;
    //                 $document->created_date = $created_date;

    //                 // Handle FTP storage name
    //                 if ($document->uploaded_method === 'ftp') {
    //                     $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
    //                 }

    //                 // Handle document preview
    //                 $document->document_preview = $document->document_preview
    //                     ? asset($document->document_preview)
    //                     : asset('uploads/document_previews/default.png');
    //             }

    //             return response()->json($documents);
    //         }
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => "fail",
    //             'message' => 'Request failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    // public function view_document($id,$user,Request $request)
    // {
    //     try {

    //         $document = Documents::findOrFail($id);
    //         $user_details = UserDetails::where('user_id',$user)->first();
    //         $date_time = Carbon::now()->format('Y-m-d H:i:s');

    //     if ($document->uploaded_method == 'ftp') {
    //         $document_details = Documents::where('id', $id)->first();
    //         $ftpAccount = FTPAccounts::findOrFail($document_details->storage);

    //         config([
    //             'filesystems.disks.dynamic_ftp' => [
    //                 'driver' => 'ftp',
    //                 'host' => $ftpAccount->host,
    //                 'username' => $ftpAccount->username,
    //                 'password' => $ftpAccount->password,
    //                 'port' => (int) $ftpAccount->port,
    //                 'root' => ltrim($ftpAccount->root_path, '/'),
    //             ],
    //         ]);

    //         $disk = Storage::disk('dynamic_ftp');
    //         $filePath = $document_details->file_path;

    //         $localDisk = Storage::disk('local');
    //         $localDisk->put($filePath, $disk->get($filePath));

    //         $file_type = $document_details->type;
    //         $isImage = in_array($file_type, ['jpeg', 'png', 'gif', 'webp', 'jpg']);

    //         if ($isImage) {

    //             $manager = new ImageManager(new Driver());

    //             $image = $manager->read($localDisk->path($filePath));
    //             $watermarkTexts = ['Confidential', 'Do Not Copy', $user_details->first_name.' '.$user_details->last_name, $date_time];
    //             // $positions = [
    //             //     ['x' => 50, 'y' => 50],
    //             //     ['x' => $image->width() - 50, 'y' => 50],
    //             //     ['x' => 50, 'y' => $image->height() - 50],
    //             //     ['x' => $image->width() - 50, 'y' => $image->height() - 50],
    //             //     ['x' => $image->width() / 2, 'y' => $image->height() / 2],
    //             // ];
    //             // foreach ($positions as $position) {
    //             //     foreach ($watermarkTexts as $key => $text) {
    //             //         $image->text(
    //             //             $text,
    //             //             $position['x'],
    //             //             $position['y'] + ($key * 30),
    //             //             function ($font) {
    //             //                 $font->filename(storage_path('fonts/roboto.ttf'));
    //             //                 $font->size(24);
    //             //                 $font->color('rgba(255, 255, 255, 0.64)');
    //             //                 $font->align('center');
    //             //                 $font->valign('middle');
    //             //             }
    //             //         );
    //             //     }
    //             // }
    //             foreach ($watermarkTexts as $key => $text) {
    //                 $image->text(
    //                     $text,
    //                     $image->width() / 2,
    //                     ($image->height() / 2) + ($key * 50),
    //                     function ($font) {
    //                         $font->filename(storage_path('fonts/roboto.ttf'));
    //                         $font->size(48); 
    //                         $font->color('rgba(255, 255, 255, 0.64)'); 
    //                         $font->align('center'); 
    //                         $font->valign('middle');
    //                     }
    //                 );
    //             }

    //             $image->save($localDisk->path($filePath));
    //         }

    //         $tempUrl = $localDisk->temporaryUrl($filePath, now()->addHour());

    //         $auditFunction = new CommonFunctionsController();
    //         $auditFunction->document_audit_trail('document viewed', $user, $id, $date_time, null, null);

    //         return response()->json([
    //             'status' => "success",
    //             'data' => $tempUrl,
    //         ], 200);
    //         }
    //         else{
    //         if ($document->is_encrypted == '1') {

    //             $encryptedFilePath = storage_path('app/private/' . $document->file_path);
    //             $ivPath = storage_path('app/private/' . str_replace('.enc', '.iv', $document->file_path));
    //             $encryptionKey = env('FILE_ENCRYPTION_KEY'); 
    //             $cipher = $document->encryption_type == '128bit' ? 'AES-128-CBC' : 'AES-256-CBC';

    //             if (!file_exists($encryptedFilePath) || !file_exists($ivPath)) {
    //                 return response()->json([
    //                     'status' => "fail",
    //                     'message' => 'Encrypted file or IV not found.'
    //                 ], 404);
    //             }

    //             $encryptedContent = file_get_contents($encryptedFilePath);
    //             $iv = file_get_contents($ivPath);

    //             $decryptFile = new CommonFunctionsController();
    //             $decryptedContent=$decryptFile->decryptFile($encryptedContent, $iv, $encryptionKey, $cipher);

    //             if ($decryptedContent === false) {
    //                 return response()->json([
    //                     'status' => "fail",
    //                     'message' => 'Failed to decrypt the file.'
    //                 ], 500);
    //             }

    //             $tempFileName = 'decrypted_' . time() . '_' . $document->name;
    //             $tempFilePath = 'temporary/' . $tempFileName;
    //             Storage::disk('local')->put($tempFilePath, $decryptedContent);

    //             $tempUrl = Storage::disk('local')->temporaryUrl(
    //                 $tempFilePath,
    //                 now()->addHour()
    //             );

    //             $auditFunction = new CommonFunctionsController();
    //             $auditFunction->document_audit_trail('document viewed', $user, $id, $date_time, null, null);

    //             return response()->json([
    //                 'status' => "success",
    //                 'data' => $tempUrl
    //             ], 200);
    //         } else {

    //             $filePath = $document->file_path;
    //             $tempUrl = Storage::disk('local')->temporaryUrl(
    //                 $filePath,
    //                 now()->addHour()
    //             );

    //             $auditFunction = new CommonFunctionsController();
    //             $auditFunction->document_audit_trail('document viewed', $user, $id, $date_time, null, null);

    //             return response()->json([
    //                 'status' => "success",
    //                 'data' => $tempUrl
    //             ], 200);
    //         }

    //     }

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => "fail",
    //             'message' => 'Request failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function view_document($id, $user, Request $request)
    {
        try {
            $document = Documents::where('id', $id)->with(['category' => function ($query) {
                $query->select('id', 'category_name');
            }])
                ->first();
            $user_details = UserDetails::where('user_id', $user)->first();
            $date_time = Carbon::now()->format('Y-m-d H:i:s');

            $retreiveDocument = new CommonFunctionsController();
            $tempUrl = $retreiveDocument->retreive_document($document, $user_details, false);

            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document viewed', 'document', $user, $id, $date_time, null, null);

            $document->url = $tempUrl;
            $document->enable_external_file_view = 1;
            return response()->json([
                'status' => "success",
                'data' => $document,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function download_document($id, $user, Request $request)
    {
        try {
            $document = Documents::where('id', $id)->with(['category' => function ($query) {
                $query->select('id', 'category_name');
            }])
                ->first();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');

            $retreiveDocument = new CommonFunctionsController();
            $tempUrl = $retreiveDocument->retreive_document($document, null, true);

            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document downloaded', 'document', $user, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'data' => $tempUrl,
                'type' => $document->type,
                'name' => $document->name,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function document_share($id, Request $request)
    {

        try {

            if ($request->isMethod('get')) {
                $shared_roles = DocumentSharedRoles::where('document_id', $id)->get();
                $shared_users = DocumentSharedUsers::where('document_id', $id)->get();
                $roles_and_users = [];

                foreach ($shared_roles as $shared_role) {
                    $roles_and_users[] = [
                        'id' => $shared_role->id,
                        'type' => 'role',
                        'allow_download' => $shared_role->is_downloadable,
                        'name' => Roles::where('id', $shared_role->role)->value('role_name'),
                        'email' => null,
                        'start_date_time' => $shared_role->start_date_time,
                        'end_date_time' => $shared_role->end_date_time,
                    ];
                }

                foreach ($shared_users as $shared_user) {
                    $roles_and_users[] = [
                        'id' => $shared_user->id,
                        'type' => 'user',
                        'allow_download' => $shared_user->is_downloadable,
                        'name' => UserDetails::where('user_id', $shared_user->user)->value('first_name') . ' ' . UserDetails::where('user_id', $shared_user->user)->value('last_name'),
                        'email' => User::where('id', $shared_user->user)->value('email'),
                        'start_date_time' => $shared_user->start_date_time,
                        'end_date_time' => $shared_user->end_date_time,
                    ];
                }
                return response()->json($roles_and_users);
            }
            if ($request->isMethod('post')) {

                $validator = Validator::make($request->all(), [
                    'type' => 'required'
                ]);


                if ($validator->fails()) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }

                if ($request->type == 'role') {
                    if ($request->assigned_roles_or_users) {
                        foreach (json_decode($request->assigned_roles_or_users, true) as $assigned_role_or_user) {
                            if (DocumentSharedRoles::where("document_id",  $id)->where("role", $assigned_role_or_user)->exists()) {
                                $shared_role = DocumentSharedRoles::where("document_id",  $id)->where("role", $assigned_role_or_user)->first();
                                $shared_role->is_time_limited = $request->is_time_limited;
                                $shared_role->start_date_time = $request->start_date_time;
                                $shared_role->end_date_time = $request->end_date_time;
                                $shared_role->is_downloadable = $request->is_downloadable;
                                $shared_role->update();
                            } else {
                                $shared_role = new DocumentSharedRoles();
                                $shared_role->document_id = $id;
                                $shared_role->role = $assigned_role_or_user;
                                $shared_role->is_time_limited = $request->is_time_limited;
                                $shared_role->start_date_time = $request->start_date_time;
                                $shared_role->end_date_time = $request->end_date_time;
                                $shared_role->is_downloadable = $request->is_downloadable;
                                $shared_role->save();
                            }
                        }
                        $date_time = Carbon::now()->format('Y-m-d H:i:s');
                        $auditFunction = new CommonFunctionsController();
                        $auditFunction->document_audit_trail('document shared for roles', 'document', $request->user, $id, $date_time, $request->assigned_roles_or_users, null);
                    }
                } else {

                    if ($request->assigned_roles_or_users) {
                        foreach (json_decode($request->assigned_roles_or_users, true) as $assigned_role_or_user) {
                            if (DocumentSharedUsers::where("document_id",  $id)->where("user", $assigned_role_or_user)->exists()) {
                                $shared_user = DocumentSharedUsers::where("document_id",  $id)->where("user", $assigned_role_or_user)->first();
                                $shared_user->is_time_limited = $request->is_time_limited;
                                $shared_user->start_date_time = $request->start_date_time;
                                $shared_user->end_date_time = $request->end_date_time;
                                $shared_user->is_downloadable = $request->is_downloadable;

                                $shared_user->update();
                            } else {
                                $shared_user = new DocumentSharedUsers();
                                $shared_user->document_id = $id;
                                $shared_user->user = $assigned_role_or_user;
                                $shared_user->is_time_limited = $request->is_time_limited;
                                $shared_user->start_date_time = $request->start_date_time;
                                $shared_user->end_date_time = $request->end_date_time;
                                $shared_user->is_downloadable = $request->is_downloadable;
                                $shared_user->save();
                            }
                        }
                        $date_time = Carbon::now()->format('Y-m-d H:i:s');
                        $auditFunction = new CommonFunctionsController();
                        $auditFunction->document_audit_trail('document shared for users', 'document', $request->user, $id, $date_time, null, $request->assigned_roles_or_users);
                    }
                }
                return response()->json([
                    'status' => "success"
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
    public function document_bulk_share(Request $request)
    {

        try {

            if ($request->isMethod('post')) {

                $validator = Validator::make($request->all(), [
                    'documents' => 'required'
                ]);


                if ($validator->fails()) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $date_time = Carbon::now()->format('Y-m-d H:i:s');

                foreach (json_decode($request->documents, true) as $document) {

                    if ($request->shared_roles) {
                        foreach (json_decode($request->shared_roles, true) as $shared_role_data) {
                            if (DocumentSharedRoles::where("document_id",  $document)->where("role", $shared_role_data)->exists()) {
                                $shared_role = DocumentSharedRoles::where("document_id",  $document)->where("role", $shared_role_data)->first();
                                $shared_role->is_time_limited = $request->is_time_limited;
                                $shared_role->start_date_time = $request->start_date_time;
                                $shared_role->end_date_time = $request->end_date_time;
                                $shared_role->is_downloadable = $request->is_downloadable;
                                $shared_role->update();
                            } else {
                                $shared_role = new DocumentSharedRoles();
                                $shared_role->document_id = $document;
                                $shared_role->role = $shared_role_data;
                                $shared_role->is_time_limited = $request->is_time_limited;
                                $shared_role->start_date_time = $request->start_date_time;
                                $shared_role->end_date_time = $request->end_date_time;
                                $shared_role->is_downloadable = $request->is_downloadable;
                                $shared_role->save();
                            }
                        }
                    }
                    if ($request->shared_users) {
                        foreach (json_decode($request->shared_users, true) as $shared_user_data) {
                            if (DocumentSharedUsers::where("document_id",  $document)->where("user", $shared_user_data)->exists()) {
                                $shared_user = DocumentSharedUsers::where("document_id",  $document)->where("user", $shared_user_data)->first();
                                $shared_user->is_time_limited = $request->is_time_limited;
                                $shared_user->start_date_time = $request->start_date_time;
                                $shared_user->end_date_time = $request->end_date_time;
                                $shared_user->is_downloadable = $request->is_downloadable;
                                $shared_user->update();
                            } else {
                                $shared_user = new DocumentSharedUsers();
                                $shared_user->document_id = $document;
                                $shared_user->user = $shared_user_data;
                                $shared_user->is_time_limited = $request->is_time_limited;
                                $shared_user->start_date_time = $request->start_date_time;
                                $shared_user->end_date_time = $request->end_date_time;
                                $shared_user->is_downloadable = $request->is_downloadable;
                                $shared_user->save();
                            }
                        }
                    }

                    $auditFunction = new CommonFunctionsController();
                    $auditFunction->document_audit_trail('document shared', 'document', $request->user, $document, $date_time, $request->shared_roles, $request->shared_users);
                }

                return response()->json([
                    'status' => "success"
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
    public function delete_share($type, $id, Request $request)
    {

        try {
            if ($type == 'role') {

                // $document_id =  DocumentSharedRoles::where('id', '=', $id)->value('document_id');

                DocumentSharedRoles::where('id', '=', $id)->delete();

                // $date_time = Carbon::now()->format('Y-m-d H:i:s');
                // $auditFunction = new CommonFunctionsController();
                // $auditFunction->document_audit_trail('document shared role deleted', $user, $document_id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Role Deleted'
                ], 201);
            } else {
                DocumentSharedUsers::where('id', '=', $id)->delete();
                return response()->json([
                    'status' => "success",
                    'message' => 'User Deleted'
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
    public function get_shareble_link($id, Request $request)
    {

        try {

            if ($request->isMethod('get')) {
                $link =  DocumentSharedLinks::select('id', 'document_id', 'has_expire_date', 'expire_date_time', 'has_password', 'allow_download', 'link')
                    ->where('document_id', $id)->first();;
                return response()->json($link);
            }
            if ($request->isMethod('post')) {

                if (DocumentSharedLinks::where("document_id", $id)->exists()) {
                    $update_link =  DocumentSharedLinks::where('document_id', $id)->first();;
                    $update_link->has_expire_date = $request->has_expire_date;
                    $update_link->expire_date_time = $request->expire_date_time;
                    $update_link->has_password = $request->has_password;
                    if (!is_null($request->password)) {
                        $update_link->password = $request->password;
                    }
                    $update_link->allow_download = $request->allow_download;
                    $update_link->update();

                    return response()->json([
                        'status' => "success",
                        'link' => $update_link->link
                    ], 201);
                } else {
                    $share = new DocumentSharedLinks();
                    $share->document_id = $id;
                    $share->has_expire_date = $request->has_expire_date;
                    $share->expire_date_time = $request->expire_date_time;
                    $share->has_password = $request->has_password;
                    $share->password = $request->password;
                    $share->allow_download = $request->allow_download;
                    $share->save();

                    $crypt_id = Crypt::encryptString($share->id);
                    $link = 'https://dms-demo-2.vercel.app/preview/' . $crypt_id;

                    $update_link =  DocumentSharedLinks::where('id', $share->id)->first();;
                    $update_link->link = $link;
                    $update_link->update();

                    return response()->json([
                        'status' => "success",
                        'link' => $link
                    ], 201);
                }

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('shareble link created', 'document', $request->user, $id, $date_time, null, null);
            }
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function delete_shareble_link($id, $user, Request $request)
    {

        try {

            $document_id =  DocumentSharedLinks::where('id', '=', $id)->value('document_id');

            DocumentSharedLinks::where('id', '=', $id)->delete();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document shared link deleted', 'document', $user, $document_id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Document Deleted'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function unlock_shareble_link($id, Request $request)
    {

        try {

            if ($request->isMethod('get')) {
                $link_id = Crypt::decryptString($id);

                $link_details =  DocumentSharedLinks::where('id', $link_id)->first();

                if ($link_details->has_expire_date == '1') {
                    $isExpired = Carbon::parse($link_details->expire_date_time, 'UTC')
                        ->setTimezone(config('app.timezone'))
                        ->isPast();

                    if ($isExpired) {
                        return response()->json([
                            'status' => "fail",
                            'message' => 'Link expired'
                        ], 500);
                    } else {
                        if ($link_details->has_password == '1') {
                            return response()->json([
                                'status' => "fail",
                                'message' => 'Need the password to unlock'
                            ], 500);
                        } else {
                            $document = Documents::where('id', $link_details->document_id)->with(['category' => function ($query) {
                                $query->select('id', 'category_name');
                            }])
                                ->first();
                            $user = auth('api')->id();

                            $user_details = UserDetails::where('user_id', $user)->first();
                            $date_time = Carbon::now()->format('Y-m-d H:i:s');

                            $retreiveDocument = new CommonFunctionsController();
                            $tempUrl = $retreiveDocument->retreive_document($document, $user_details, false);

                            $auditFunction = new CommonFunctionsController();
                            $auditFunction->document_audit_trail('document viewed by shareble link', 'document', $user, $link_details->document_id, $date_time, null, null);

                            $document->url = $tempUrl;
                            $document->enable_external_file_view = 1;

                            return response()->json([
                                'status' => "success",
                                'data' => $document,
                                'allow_download' => $link_details->allow_download
                            ], 200);


                            // return response()->json([
                            //         'status' => "success",
                            //         'data' => $tempUrl,
                            //         'allow_download' => $link_details->allow_download
                            // ], 200);


                        }
                    }
                } else {
                    if ($link_details->has_password == '1') {
                        return response()->json([
                            'status' => "fail",
                            'message' => 'Need the password to unlock'
                        ], 500);
                    } else {
                        $document = Documents::where('id', $link_details->document_id)->with(['category' => function ($query) {
                            $query->select('id', 'category_name');
                        }])
                            ->first();
                        $user = auth('api')->id();

                        $user_details = UserDetails::where('user_id', $user)->first();
                        $date_time = Carbon::now()->format('Y-m-d H:i:s');

                        $retreiveDocument = new CommonFunctionsController();
                        $tempUrl = $retreiveDocument->retreive_document($document, $user_details, false);

                        $auditFunction = new CommonFunctionsController();
                        $auditFunction->document_audit_trail('document viewed by shareble link', 'document', $user, $link_details->document_id, $date_time, null, null);


                        $document->url = $tempUrl;
                        $document->enable_external_file_view = 1;

                        return response()->json([
                            'status' => "success",
                            'data' => $document,
                            'allow_download' => $link_details->allow_download
                        ], 200);
                    }
                }
            }
            if ($request->isMethod('post')) {

                $validator = Validator::make($request->all(), [
                    'password' => 'required'
                ]);


                if ($validator->fails()) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $link_id = Crypt::decryptString($id);

                $link_details =  DocumentSharedLinks::where('id', $link_id)->first();

                if ($request->password == $link_details->password) {

                    $document = Documents::where('id', $link_details->document_id)->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                        ->first();
                    $user = auth('api')->id();

                    $user_details = UserDetails::where('user_id', $user)->first();
                    $date_time = Carbon::now()->format('Y-m-d H:i:s');

                    $retreiveDocument = new CommonFunctionsController();
                    $tempUrl = $retreiveDocument->retreive_document($document, $user_details, false);

                    $auditFunction = new CommonFunctionsController();
                    $auditFunction->document_audit_trail('document viewed by shareble link', 'document', $user, $link_details->document_id, $date_time, null, null);


                    $document->url = $tempUrl;
                    $document->enable_external_file_view = 1;

                    return response()->json([
                        'status' => "success",
                        'data' => $document,
                        'allow_download' => $link_details->allow_download
                    ], 200);
                } else {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'password incorrect'
                    ], 500);
                }
            }
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function document_upload_new_version($id, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document' => 'required|file|mimes:pdf,doc,docx,jpg,png,xls,xlsx',
                'user' => 'required',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $document_details = Documents::where('id', '=', $id)->first();
            if ($document_details->uploaded_method == 'direct' || $document_details->uploaded_method == 'bulk') {
                $file_name = time() . '-' . Str::uuid()->toString() . '.' . $request->document->getClientOriginalExtension();
                $filePath = $request->document->storeAs('documents', $file_name, 'local');
                $date_time = Carbon::now()->format('Y-m-d H:i:s');

                $file_path_full = storage_path("app/private/$filePath");

                // $getFileTextContent = new CommonFunctionsController();
                // $content = $getFileTextContent->sendToNode($file_path_full);

                $response = Http::attach(
                    'file',
                    file_get_contents($file_path_full),
                    $request->document->getClientOriginalName()
                )->post('http://127.0.0.1:8001/extract');

                $content = $response->json()['pages'] ?? [];

                $document = Documents::where('id', '=', $id)->first();
                $document->type = $request->document->getClientOriginalExtension();
                $document->file_path = $filePath;
                $document->update();

                $version = new DocumentVersions();
                $version->document_id = $id;
                $version->type = $request->document->getClientOriginalExtension();
                $version->file_path = $filePath;
                $version->date_time = $date_time;
                $version->user = $request->user;
                $version->save();

                $deleteFileTextContent = new CommonFunctionsController();
                $deleteFileTextContent->deleteDocumentFromIndex($id);
                $indexFileTextContent = new CommonFunctionsController();
                $indexFileTextContent->indexDocumentContent($id, $content);

                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document new version added', 'document', $request->user, $id, $date_time, null, null);
            } else {
                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $ftp_account = Categories::where('id', $document_details->category)->value('ftp_account');
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
                // $fileName = $uploadedFile->getClientOriginalName();
                $fileName = time() . '-' . Str::uuid()->toString() . '.' . $request->document->getClientOriginalExtension();

                $stream = fopen($uploadedFile->getRealPath(), 'r+');
                if (!Storage::disk('dynamic_ftp')->put($fileName, $stream)) {
                    fclose($stream);
                    return response()->json([
                        'status' => "fail",
                        'message' => "Failed to upload file: $fileName to the FTP server."
                    ], 500);
                }
                fclose($stream);

                $document = Documents::where('id', '=', $id)->first();
                $document->type = $request->document->getClientOriginalExtension();
                $document->file_path = $ftp_root . $fileName;
                $document->update();

                $version = new DocumentVersions();
                $version->document_id = $id;
                $version->type = $request->document->getClientOriginalExtension();
                $version->file_path =  $ftp_root . $fileName;
                $version->date_time = $date_time;
                $version->user = $request->user;
                $version->save();

                $file_path_full = storage_path("app/private/temp/$fileName");
                $ftpDisk = Storage::disk('dynamic_ftp');
                $localDisk = Storage::disk('local');

                if ($ftpDisk->exists($fileName)) {
                    $fileContent = $ftpDisk->get($fileName);
                    $localDisk->put("temp/$fileName", $fileContent);
                } else {
                    return response()->json([
                        'status' => "fail",
                        'message' => "Failed to retrieve file from FTP."
                    ], 500);
                }

                // $getFileTextContent = new CommonFunctionsController();
                // $content = $getFileTextContent->sendToNode($file_path_full);

                $response = Http::attach(
                    'file',
                    file_get_contents($file_path_full),
                    $request->document->getClientOriginalName()
                )->post('http://127.0.0.1:8001/extract');

                $content = $response->json()['pages'] ?? [];

                $deleteFileTextContent = new CommonFunctionsController();
                $deleteFileTextContent->deleteDocumentFromIndex($id);
                $indexFileTextContent = new CommonFunctionsController();
                $indexFileTextContent->indexDocumentContent($id, $content);


                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document new version added', 'document', $request->user, $id, $date_time, null, null);
            }

            return response()->json([
                'status' => "success",
                'message' => 'Document updated.'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function document_version_history($id, Request $request)
    {
        try {
            $document_versions = DocumentVersions::select('type', 'date_time', 'user')->where('document_id', $id)->orderBy('id', 'DESC')->get();;

            foreach ($document_versions as $document_version) {

                $user_details = UserDetails::where('user_id', $document_version->user)->first();

                $created_by = $user_details->value('first_name') . ' ' . $user_details->value('last_name');

                $document_version->created_by = $created_by;
            }
            return response()->json($document_versions);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function document_comments($id, Request $request)
    {

        try {
            if ($request->isMethod('get')) {
                $comments = DocumentComments::select('id', 'comment', 'date_time', 'user')->where('document_id', $id)->orderBy('id', 'DESC')->get();;

                foreach ($comments as $comment) {

                    $user_details = UserDetails::where('user_id', $comment->user)->first();

                    $created_by = $user_details->value('first_name') . ' ' . $user_details->value('last_name');
                    $comment->commented_by = $created_by;
                }
                return response()->json($comments);
            }
            if ($request->isMethod('post')) {

                $validator = Validator::make($request->all(), [
                    'user' => 'required',
                    'comment' => 'required'
                ]);


                if ($validator->fails()) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $date_time =  Carbon::now()->format('Y-m-d H:i:s');

                $doc_comment = new DocumentComments();
                $doc_comment->document_id = $id;
                $doc_comment->comment = $request->comment;
                $doc_comment->date_time = $date_time;
                $doc_comment->user = $request->user;
                $doc_comment->save();

                $date_time =  Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document comment added', 'document', $request->user, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Comment Added'
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

    public function delete_comment($id, $user, Request $request)
    {

        try {

            $document_id =  DocumentComments::where('id', '=', $id)->value('document_id');

            DocumentComments::where('id', '=', $id)->delete();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document comment deleted', 'document', $user, $document_id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Comment Deleted'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function document_send_email($id, Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $validator = Validator::make($request->all(), [
                    'to' => 'email|required',
                    'subject' => 'required',
                    'body' => 'required'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $document = Documents::where('id', $id)->with(['category' => function ($query) {
                    $query->select('id', 'category_name');
                }])
                    ->first();
                $user_details = UserDetails::where('user_id', $request->user)->first();
                $date_time = Carbon::now()->format('Y-m-d H:i:s');

                $retreiveDocument = new CommonFunctionsController();
                $tempUrl = $retreiveDocument->retreive_document($document, null, true);

                $details = [
                    'subject' => $request->subject,
                    'body' => $request->body,
                    'attachment' => $tempUrl,
                ];


                Mail::to($request->to)->send(new \App\Mail\SendDocument($details));


                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document mailed', 'document', $request->user, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Email Sent.'
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


    public function document_archive($id, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user' => 'required',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }


            $document = Documents::where('id', $id)->first();;
            $document->is_archived = 1;
            $document->update();

            $date_time =  Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document archived', 'document', $request->user, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Document archived.'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // old approve document function
    // public function document_approve($id, Request $request)
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'user' => 'required',
    //         ]);


    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'status' => "fail",
    //                 'message' => 'Validation errors',
    //                 'errors' => $validator->errors()
    //             ], 422);
    //         }


    //         $document = Documents::where('id', $id)->first();;
    //         $document->is_approved = 1;
    //         $document->update();

    //         $date_time =  Carbon::now()->format('Y-m-d H:i:s');
    //         $auditFunction = new CommonFunctionsController();
    //         $auditFunction->document_audit_trail('document approved', 'document', $request->user, $id, $date_time, null, null);

    //         return response()->json([
    //             'status' => "success",
    //             'message' => 'Document approved.'
    //         ], 201);
    //     } catch (\Exception $e) {

    //         return response()->json([
    //             'status' => "fail",
    //             'message' => 'Request failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function document_approve($id, Request $request)
    {
        try {
            // basic validation
            $validator = Validator::make($request->all(), [
                'user' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // get logged user id and role
            $loggedUserId = (string) auth('api')->id();
            $loggedUserRole = auth('api')->user()->role ?? null;

            // get document
            $document = Documents::find($id);
            if (!$document) {
                return response()->json([
                    'status' => "fail",
                    'message' => "Document not found"
                ], 404);
            }

            // get category + approval type
            $category = $document->category()->first();
            if (!$category) {
                return response()->json([
                    'status' => "fail",
                    'message' => "Category not found"
                ], 400);
            }

            $approvalType = $category->approval_type;

            // decode approver list
            $approverList = json_decode($document->approver_ids, true);
            if (!$approverList || !is_array($approverList)) {
                return response()->json([
                    'status' => "fail",
                    'message' => "Invalid approver list format"
                ], 400);
            }

            // find the correct approver entry
            $updated = false;

            foreach ($approverList as &$item) {

                // approval type = users (match by user id)
                if ($approvalType === 'users' && (string)$item['id'] === $loggedUserId) {
                    $item['is_accepted'] = 1;
                    $updated = true;
                }

                // approval type = roles (match by role name)
                if ($approvalType === 'roles' && $loggedUserRole !== null && (string)$item['id'] === (string)$loggedUserRole) {
                    $item['is_accepted'] = 1;
                    $updated = true;
                }
            }

            if (!$updated) {
                return response()->json([
                    'status' => "fail",
                    'message' => "You are not in the approver list"
                ], 403);
            }

            // save back updated approver list
            $document->approver_ids = json_encode($approverList);

            // check if all approvers accepted
            $allAccepted = true;
            foreach ($approverList as $item) {
                if ((int)$item['is_accepted'] !== 1) {
                    $allAccepted = false;
                    break;
                }
            }

            // if all accepted → update is_approved column
            if ($allAccepted) {
                $document->is_approved = 1;
            }

            $document->save();

            // audit
            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail(
                'document approved',
                'document',
                $request->user,
                $id,
                $date_time,
                null,
                null
            );

            return response()->json([
                'status' => "success",
                'message' => 'Document approved.'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function delete_document($id, $user, Request $request)
    {

        try {

            Documents::where('id', '=', $id)->delete();
            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document deleted', 'document', $user, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Document Deleted'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function document_remove_index($id, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user' => 'required',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }


            $document = Documents::where('id', $id)->first();;
            $document->is_indexed = 1;
            $document->update();

            $date_time =  Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('document indexed', 'document', $request->user, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Document indexed.'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function document_audit_trial(Request $request)
    {
        try {
            if ($request->isMethod('get')) {
                $audit_trial = DocumentAuditTrial::select('id', 'operation', 'type', 'user', 'changed_source', 'assigned_roles', 'assigned_users', 'date_time')
                    ->orderBy('id', 'DESC')
                    ->get();

                foreach ($audit_trial as $trial) {

                    if ($trial->type == "document") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $document_details = Documents::withTrashed()->where('id', $trial->changed_source)->first();
                        $document_name = $document_details->name ?? 'Unknown Document';

                        $category = $document_details
                            ? Categories::withTrashed()->where('id', $document_details->category)->value('category_name')
                            : 'Unknown Category';

                        $assigned_roles = json_decode($trial->assigned_roles);
                        $assigned_users = json_decode($trial->assigned_users);

                        $role_names = [];
                        if ($assigned_roles) {
                            foreach ($assigned_roles as $role_id) {
                                $role = Roles::find($role_id);
                                if ($role) {
                                    $role_names[] = $role->role_name;
                                }
                            }
                        }

                        $user_names = [];
                        if ($assigned_users) {
                            foreach ($assigned_users as $user_id) {
                                $user_detail = UserDetails::find($user_id);
                                if ($user_detail) {
                                    $user_names[] = $user_detail->first_name . ' ' . $user_detail->last_name;
                                }
                            }
                        }

                        $trial->user = $user;
                        $trial->changed_source = $document_name;
                        $trial->category = $category;
                        $trial->assigned_roles = $role_names;
                        $trial->assigned_users = $user_names;
                    }
                    if ($trial->type == "user") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $changed_user_details = UserDetails::withTrashed()->where('user_id', $trial->changed_source)->first();

                        $changed_user = $changed_user_details
                            ? $changed_user_details->first_name . ' ' . $changed_user_details->last_name
                            : 'Unknown User';

                        $trial->user = $user;
                        $trial->changed_source = $changed_user;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "category") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $category_details = Categories::withTrashed()->where('id', $trial->changed_source)->first();

                        $category = $category_details
                            ? $category_details->category_name : 'Unknown Categoty';

                        $trial->user = $user;
                        $trial->changed_source = $category;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "sector") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $sector_details = Sectors::withTrashed()->where('id', $trial->changed_source)->first();

                        $sector = $sector_details
                            ? $sector_details->sector_name : 'Unknown Sector';

                        $trial->user = $user;
                        $trial->changed_source = $sector;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "role") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $role_details = Sectors::withTrashed()->where('id', $trial->changed_source)->first();

                        $role = $role_details
                            ? $role_details->role_name : 'Unknown Role';

                        $trial->user = $user;
                        $trial->changed_source = $role;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "reminder") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $reminder_details = Reminders::withTrashed()->where('id', $trial->changed_source)->first();

                        $reminder = $reminder_details
                            ? $reminder_details->subject : 'Unknown Reminder';

                        $trial->user = $user;
                        $trial->changed_source = $reminder;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "smtp") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $smtp_details = SMTPDetails::withTrashed()->where('id', $trial->changed_source)->first();

                        $smtp = $smtp_details
                            ? $smtp_details->host : 'Unknown Reminder';

                        $trial->user = $user;
                        $trial->changed_source = $smtp;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "company") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $trial->user = $user;
                        $trial->changed_source = null;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }
                }

                return response()->json($audit_trial);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function approval_history(Request $request)
    {
        try {
            if ($request->isMethod('get')) {
                $audit_trial = DocumentAuditTrial::select('id', 'operation', 'type', 'user', 'changed_source', 'assigned_roles', 'assigned_users', 'date_time')
                    ->where('operation', 'document approved')
                    ->orderBy('id', 'DESC')
                    ->get();

                foreach ($audit_trial as $trial) {

                    if ($trial->type == "document") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $document_details = Documents::withTrashed()->where('id', $trial->changed_source)->first();
                        $document_name = $document_details->name ?? 'Unknown Document';

                        $category = $document_details
                            ? Categories::withTrashed()->where('id', $document_details->category)->value('category_name')
                            : 'Unknown Category';



                        $trial->user = $user;
                        $trial->changed_source = $document_name;
                        $trial->category = $category;
                    }
                }

                return response()->json($audit_trial);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function archived_documents(Request $request)
    {
        try {
            if ($request->isMethod('get')) {

                // Get all archived documents
                $documents = Documents::where('is_archived', 1)
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
                    ->orderBy('id', 'DESC')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                    ->get();

                $documentIds = $documents->pluck('id');

                // Get all relevant audit records at once
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->whereIn('operation', ['document added', 'document archived'])
                    ->get(['changed_source', 'user', 'operation', 'date_time']);

                // Get all relevant users at once
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Map data to documents
                foreach ($documents as $document) {
                    // Created data
                    $created_data = $auditData->firstWhere(function ($item) use ($document) {
                        return $item->changed_source == $document->id && $item->operation == 'document added';
                    });

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // Archived data
                    $archived_data = $auditData->firstWhere(function ($item) use ($document) {
                        return $item->changed_source == $document->id && $item->operation == 'document archived';
                    });

                    if ($archived_data && isset($users[$archived_data->user])) {
                        $userDetails = $users[$archived_data->user];
                        $document->archived_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->archived_date = $archived_data->date_time;
                    } else {
                        $document->archived_by = 'Unknown User';
                        $document->archived_date = 'Unknown Date';
                    }

                    // Handle FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Handle document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function restore_archived_document($id, $user, Request $request)
    {
        try {

            $document = Documents::where('id', $id)->first();;
            $document->is_archived = 0;
            $document->update();

            $date_time =  Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('archived document restored', 'document', $user, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Document Restored.'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function assigned_documents(Request $request)
    {
        try {
            if ($request->isMethod('get')) {

                $userId = auth('api')->id();
                $userDetails = User::find($userId);
                $sector = UserDetails::where('user_id', $userId)->value('sector');

                if (!$userDetails) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Invalid User'
                    ], 500);
                }

                // Get document IDs shared with user
                $sharedDocumentIds = DocumentSharedUsers::where('user', $userId)->pluck('document_id');

                // Get all assigned documents
                $documents = Documents::where(function ($query) {
                    $query->where('is_archived', 0)->orWhereNull('is_archived');
                })
                    ->where('is_approved', 1)
                    ->where('indexed_or_encrypted', 'yes')
                    ->where(function ($query) use ($sector, $sharedDocumentIds) {
                        $query->where('sector_category', $sector)
                            ->orWhereIn('id', $sharedDocumentIds);
                    })
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                    ->get();

                $documentIds = $documents->pluck('id');

                // Preload all audit records
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                // Preload all user details
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                // Preload FTP accounts
                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Preload all shared users data
                $sharedData = DocumentSharedUsers::whereIn('document_id', $documentIds)
                    ->where('user', $userId)
                    ->get()
                    ->keyBy('document_id');

                $today = Carbon::now();

                $filteredDocuments = $documents->filter(function ($document) use ($auditData, $users, $sharedData, $today, $ftpAccounts) {
                    // Check time-limited share
                    if (isset($sharedData[$document->id]) && $sharedData[$document->id]->is_time_limited == "1") {
                        $share = $sharedData[$document->id];
                        if (!$today->between($share->start_date_time, $share->end_date_time)) {
                            return false;
                        }
                    }

                    // Attach created info
                    $created_data = $auditData->firstWhere('changed_source', $document->id);

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');

                    return true;
                });

                return response()->json($filteredDocuments->values());
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function assigned_documents_by_user($userId, Request $request)
    {
        try {
            if ($request->isMethod('get')) {

                $userDetails = User::find($userId);

                if (!$userDetails) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Invalid User'
                    ], 500);
                }

                // Get document IDs shared with this user
                $sharedDocumentIds = DocumentSharedUsers::where('user', $userId)->pluck('document_id');

                // Fetch documents
                $documents = Documents::where(function ($query) {
                    $query->where('is_archived', 0)->orWhereNull('is_archived');
                })
                    ->where('is_approved', 1)
                    ->where('indexed_or_encrypted', 'yes')
                    ->whereIn('id', $sharedDocumentIds)
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                    ->get();

                $documentIds = $documents->pluck('id');

                // Preload all audit records
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                // Preload all user details
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Preload all shared users data
                $sharedData = DocumentSharedUsers::whereIn('document_id', $documentIds)
                    ->where('user', $userId)
                    ->get()
                    ->keyBy('document_id');

                $today = Carbon::now();

                // Filter and attach data
                $filteredDocuments = $documents->filter(function ($document) use ($auditData, $users, $sharedData, $today) {

                    // Time-limited check
                    if (isset($sharedData[$document->id]) && $sharedData[$document->id]->is_time_limited == "1") {
                        $share = $sharedData[$document->id];
                        if (!$today->between($share->start_date_time, $share->end_date_time)) {
                            return false;
                        }
                    }

                    // Attach created info
                    $created_data = $auditData->firstWhere('changed_source', $document->id);

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');

                    return true;
                });

                return response()->json($filteredDocuments->values());
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function filter_assigned_documents(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $userId = auth('api')->id();
                $userDetails = User::find($userId);

                if (!$userDetails) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Invalid User'
                    ], 500);
                }

                $sector = UserDetails::where('user_id', $userId)->value('sector');

                // Get document IDs shared with user
                $sharedDocumentIds = DocumentSharedUsers::where('user', $userId)->pluck('document_id');

                // Base query (sector OR shared)
                $documentsQuery = Documents::where(function ($query) {
                    $query->where('is_archived', 0)->orWhereNull('is_archived');
                })
                    ->where('is_approved', 1)
                    ->where('indexed_or_encrypted', 'yes')
                    ->where(function ($query) use ($sector, $sharedDocumentIds) {
                        $query->where('sector_category', $sector)
                            ->orWhereIn('id', $sharedDocumentIds);
                    })
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }]);

                // Filters
                if ($request->filled('term')) {
                    $term = $request->term;
                    $documentsQuery->where(function ($query) use ($term) {
                        $query->where('name', 'LIKE', "%$term%")
                            ->orWhere('description', 'LIKE', "%$term%");
                    });
                }

                if ($request->filled('category')) {
                    $documentsQuery->where('category', $request->category);
                }

                if ($request->filled('meta_tags')) {
                    $documentsQuery->whereJsonContains('meta_tags', $request->meta_tags);
                }

                if ($request->filled('created_date')) {
                    $documentsQuery->whereDate('created_at', $request->created_date);
                }

                if ($request->filled('storage')) {
                    $documentsQuery->where('storage', $request->storage);
                }

                $documents = $documentsQuery->get();
                $documentIds = $documents->pluck('id');

                // Preload shared users and audit data
                $sharedData = DocumentSharedUsers::whereIn('document_id', $documentIds)
                    ->where('user', $userId)
                    ->get()
                    ->keyBy('document_id');

                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                $today = Carbon::now();

                // Filter documents and attach info
                $filteredDocuments = $documents->filter(function ($document) use ($sharedData, $auditData, $users, $today, $ftpAccounts) {

                    // If it’s shared, apply time-limited check
                    if (isset($sharedData[$document->id])) {
                        $share = $sharedData[$document->id];

                        if ($share->is_time_limited == "1") {
                            if (!$today->between($share->start_date_time, $share->end_date_time)) {
                                return false;
                            }
                        }
                    }

                    // Attach created info
                    $created = $auditData->firstWhere('changed_source', $document->id);
                    if ($created && isset($users[$created->user])) {
                        $userDetails = $users[$created->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');

                    return true;
                });

                return response()->json($filteredDocuments->values());
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function filter_assigned_documents_by_user($user, Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $userDetails = User::find($user);
                if (!$userDetails) {
                    return response()->json([
                        'status' => "fail",
                        'message' => 'Invalid User'
                    ], 500);
                }

                // Base query
                $documentsQuery = Documents::where(function ($query) {
                    $query->where('is_archived', 0)->orWhereNull('is_archived');
                })
                    ->where('indexed_or_encrypted', 'yes')
                    ->where('is_approved', 1)
                    ->select('id', 'name', 'type', 'storage', 'category', 'description', 'meta_tags', 'uploaded_method', 'document_preview')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }]);

                // Filters
                if ($request->has('term') && $request->term) {
                    $term = $request->term;
                    $documentsQuery->where(function ($query) use ($term) {
                        $query->where('name', 'LIKE', "%$term%")
                            ->orWhere('description', 'LIKE', "%$term%");
                    });
                }

                if ($request->has('category') && $request->category) {
                    $documentsQuery->where('category', $request->category);
                }

                if ($request->has('meta_tags') && $request->meta_tags) {
                    $documentsQuery->whereJsonContains('meta_tags', $request->meta_tags);
                }

                if ($request->has('created_date') && $request->created_date) {
                    $documentsQuery->whereDate('created_at', $request->created_date);
                }

                if ($request->has('storage') && $request->storage) {
                    $documentsQuery->where('storage', $request->storage);
                }

                $documents = $documentsQuery->get();
                $documentIds = $documents->pluck('id');

                // Preload shared users and audit data
                $sharedData = DocumentSharedUsers::whereIn('document_id', $documentIds)
                    ->where('user', $user)
                    ->get()
                    ->keyBy('document_id');

                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                $today = Carbon::now();

                // Filter documents and attach info
                $filteredDocuments = $documents->filter(function ($document) use ($sharedData, $auditData, $users, $today) {

                    if (!isset($sharedData[$document->id])) {
                        return false;
                    }

                    $share = $sharedData[$document->id];

                    // Time-limited check
                    if ($share->is_time_limited == "1") {
                        if (!$today->between($share->start_date_time, $share->end_date_time)) {
                            return false;
                        }
                    }

                    // Attach created info
                    $created = $auditData->firstWhere('changed_source', $document->id);
                    if ($created && isset($users[$created->user])) {
                        $userDetails = $users[$created->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');

                    return true;
                });

                return response()->json($filteredDocuments->values());
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function filter_all_documents(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $documentsQuery = Documents::select(
                    'id',
                    'name',
                    'type',
                    'storage',
                    'category',
                    'description',
                    'meta_tags',
                    'uploaded_method',
                    'document_preview'
                )
                    ->where('indexed_or_encrypted', 'yes')
                    ->where(function ($query) {
                        $query->where('is_archived', '!=', 1)
                            ->orWhereNull('is_archived');
                    })
                    ->where('is_approved', 1)
                    ->orderBy('id', 'DESC')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }]);


                // Apply filters
                if ($request->filled('term')) {
                    $term = $request->term;
                    $documentsQuery->where('name', 'LIKE', "%$term%");
                }

                if ($request->filled('category')) {
                    $documentsQuery->where('category', $request->category);
                }

                if ($request->filled('meta_tags')) {
                    $documentsQuery->whereJsonContains('meta_tags', $request->meta_tags);
                }

                if ($request->filled('created_date')) {
                    $documentsQuery->whereDate('created_at', $request->created_date);
                }

                if ($request->filled('storage')) {
                    $documentsQuery->where('storage', $request->storage);
                }

                $documents = $documentsQuery->get();
                $documentIds = $documents->pluck('id');

                // Load all relevant audit records at once
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                // Load all relevant users at once
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Map audit and user data to documents
                foreach ($documents as $document) {
                    $created_data = $auditData->firstWhere('changed_source', $document->id);

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function filter_audit_trial(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $audit_trial = DocumentAuditTrial::select('id', 'operation', 'type', 'user', 'changed_source', 'assigned_roles', 'assigned_users', 'date_time')
                    ->orderBy('id', 'DESC');

                if ($request->has('user') && $request->user) {
                    $user = $request->user;
                    $audit_trial = $audit_trial->where('user', $user);
                }

                if ($request->has('date') && $request->date) {
                    $date = $request->date;
                    $audit_trial = $audit_trial->whereDate('date_time', $date);
                }
                if ($request->has('type') && $request->type) {
                    $type = $request->type;
                    $audit_trial = $audit_trial->where('type', $type);
                }

                $audit_trial = $audit_trial->get();

                foreach ($audit_trial as $trial) {

                    if ($trial->type == "document") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $document_details = Documents::withTrashed()->where('id', $trial->changed_source)->first();
                        $document_name = $document_details->name ?? 'Unknown Document';

                        $category = $document_details
                            ? Categories::withTrashed()->where('id', $document_details->category)->value('category_name')
                            : 'Unknown Category';

                        $assigned_roles = json_decode($trial->assigned_roles);
                        $assigned_users = json_decode($trial->assigned_users);

                        $role_names = [];
                        if ($assigned_roles) {
                            foreach ($assigned_roles as $role_id) {
                                $role = Roles::find($role_id);
                                if ($role) {
                                    $role_names[] = $role->role_name;
                                }
                            }
                        }

                        $user_names = [];
                        if ($assigned_users) {
                            foreach ($assigned_users as $user_id) {
                                $user_detail = UserDetails::find($user_id);
                                if ($user_detail) {
                                    $user_names[] = $user_detail->first_name . ' ' . $user_detail->last_name;
                                }
                            }
                        }

                        $trial->user = $user;
                        $trial->changed_source = $document_name;
                        $trial->category = $category;
                        $trial->assigned_roles = $role_names;
                        $trial->assigned_users = $user_names;
                    }
                    if ($trial->type == "user") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $changed_user_details = UserDetails::withTrashed()->where('user_id', $trial->changed_source)->first();

                        $changed_user = $changed_user_details
                            ? $changed_user_details->first_name . ' ' . $changed_user_details->last_name
                            : 'Unknown User';

                        $trial->user = $user;
                        $trial->changed_source = $changed_user;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "category") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $category_details = Categories::withTrashed()->where('id', $trial->changed_source)->first();

                        $category = $category_details
                            ? $category_details->category_name : 'Unknown Categoty';

                        $trial->user = $user;
                        $trial->changed_source = $category;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "sector") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $sector_details = Sectors::withTrashed()->where('id', $trial->changed_source)->first();

                        $sector = $sector_details
                            ? $sector_details->sector_name : 'Unknown Sector';

                        $trial->user = $user;
                        $trial->changed_source = $sector;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "role") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $role_details = Sectors::withTrashed()->where('id', $trial->changed_source)->first();

                        $role = $role_details
                            ? $role_details->role_name : 'Unknown Role';

                        $trial->user = $user;
                        $trial->changed_source = $role;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "reminder") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $reminder_details = Reminders::withTrashed()->where('id', $trial->changed_source)->first();

                        $reminder = $reminder_details
                            ? $reminder_details->subject : 'Unknown Reminder';

                        $trial->user = $user;
                        $trial->changed_source = $reminder;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "smtp") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $smtp_details = SMTPDetails::withTrashed()->where('id', $trial->changed_source)->first();

                        $smtp = $smtp_details
                            ? $smtp_details->host : 'Unknown Reminder';

                        $trial->user = $user;
                        $trial->changed_source = $smtp;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }

                    if ($trial->type == "company") {
                        $user_details = UserDetails::withTrashed()->where('user_id', $trial->user)->first();
                        $user = $user_details
                            ? $user_details->first_name . ' ' . $user_details->last_name
                            : 'Unknown User';

                        $trial->user = $user;
                        $trial->changed_source = null;
                        $trial->category = null;
                        $trial->assigned_roles = [];
                        $trial->assigned_users = [];
                    }
                }

                return response()->json($audit_trial);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function filter_archived_documents(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $documentsQuery = Documents::where('is_archived', 1)
                    ->where('indexed_or_encrypted', 'yes')
                    ->where('is_approved', 1)
                    ->select('id', 'name', 'type', 'storage', 'category', 'description', 'meta_tags', 'uploaded_method', 'document_preview')
                    ->orderBy('id', 'DESC')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }]);

                // Apply filters
                if ($request->filled('term')) {
                    $term = $request->term;
                    $documentsQuery->where(function ($query) use ($term) {
                        $query->where('name', 'LIKE', "%$term%")
                            ->orWhere('description', 'LIKE', "%$term%");
                    });
                }

                if ($request->filled('category')) {
                    $documentsQuery->where('category', $request->category);
                }

                if ($request->filled('meta_tags')) {
                    $documentsQuery->whereJsonContains('meta_tags', $request->meta_tags);
                }

                if ($request->filled('storage')) {
                    $documentsQuery->where('storage', $request->storage);
                }

                $documents = $documentsQuery->get();
                $documentIds = $documents->pluck('id');

                // Get audit records for all documents at once
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->whereIn('operation', ['document added', 'document archived'])
                    ->get(['changed_source', 'user', 'operation', 'date_time']);

                // Get all users involved
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');

                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Map audit and user data to documents
                foreach ($documents as $document) {
                    // Created
                    $created_data = $auditData->firstWhere(function ($item) use ($document) {
                        return $item->changed_source == $document->id && $item->operation == 'document added';
                    });

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // Archived
                    $archived_data = $auditData->firstWhere(function ($item) use ($document) {
                        return $item->changed_source == $document->id && $item->operation == 'document archived';
                    });

                    if ($archived_data && isset($users[$archived_data->user])) {
                        $userDetails = $users[$archived_data->user];
                        $document->archived_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->archived_date = $archived_data->date_time;
                    } else {
                        $document->archived_by = 'Unknown User';
                        $document->archived_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function advanced_search(Request $request)
    {
        try {
            if ($request->isMethod('post')) {

                $documentsQuery = Documents::select(
                    'id',
                    'name',
                    'type',
                    'storage',
                    'category',
                    'description',
                    'meta_tags',
                    'uploaded_method',
                    'document_preview',
                    'attributes'
                )
                    ->orderBy('id', 'DESC')
                    ->where(function ($query) {
                        $query->where('is_indexed', '!=', 1)
                            ->orWhereNull('is_indexed');
                    })
                    ->where('is_approved', 1)
                    ->where(function ($query) {
                        $query->where('is_archived', '!=', 1)
                            ->orWhereNull('is_archived');
                    })
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }]);

                // Search filters
                if ($request->filled('term')) {
                    $term = $request->term;
                    $documentsQuery->where(function ($query) use ($term) {
                        $query->where('documents.name', 'LIKE', "%$term%")
                            ->orWhere('documents.description', 'LIKE', "%$term%")
                            ->orWhereRaw("JSON_CONTAINS(meta_tags, '" . json_encode($term) . "')")
                            ->orWhereRaw("JSON_SEARCH(documents.attributes, 'all', ?) IS NOT NULL", ["%$term%"]);
                    });
                }

                $documents = $documentsQuery->get();
                $documentIds = $documents->pluck('id');

                // Load all relevant audit records at once
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                // Load all users at once
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');
                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');

                // Map audit and user data to documents
                foreach ($documents as $document) {
                    $created_data = $auditData->firstWhere('changed_source', $document->id);

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deep_search(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $query = $request->term;

                // $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
                // $index = $client->index('python_test_docs_3');
                // $searchResults = $index->search($query, [
                //     'attributesToHighlight' => ['content'],
                //     'showMatchesPosition' => true,
                // ])->getHits();
                $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
                $index = $client->index('python_test_docs_3');
                // $index->updateSettings([
                //     'filterableAttributes' => ['content'],
                // ]);

                // $searchResults = $index->search($query, [
                //     'attributesToHighlight' => ['content'],
                //     'showMatchesPosition' => true,
                //     // 'filter' => 'content = "' . $query . '"',
                // ])->getHits();

                $searchResults = $index->search('"' . $query . '"', [
                    'attributesToHighlight' => ['content'],
                    'showMatchesPosition' => true,
                ])->getHits();
                // $searchResults = $index->search($query, [
                //     'attributesToHighlight' => ['content'],
                //     'showMatchesPosition' => true,
                // ])->getHits();

                $groupedResults = [];
                foreach ($searchResults as $result) {
                    $docId = $result['sql_document'];
                    if (!isset($groupedResults[$docId])) {
                        $groupedResults[$docId] = [
                            'document_id' => $docId,
                            'pages' => []
                        ];
                    }
                    $groupedResults[$docId]['pages'][] = [
                        'page' => $result['page'],
                        'highlighted_content' => $result['_formatted']['content'] ?? ''
                    ];
                }

                $documentIds = array_keys($groupedResults);
                $documents = Documents::whereIn('id', $documentIds)
                    ->where(function ($query) {
                        $query->where('is_archived', 0)->orWhereNull('is_archived');
                    })->where(function ($query) {
                        $query->where('is_approved', 1);
                    })
                    ->where('indexed_or_encrypted', 'yes')
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview', 'sector_category')
                    ->with(['category:id,category_name'])
                    ->get();

                $finalResults = [];
                foreach ($documents as $document) {
                    $userId = auth('api')->id();
                    // if(UserDetails::where('user_id', $userId)->value('sector') == $document->sector_category){
                    $finalResults[] = [
                        'id' => $document->id,
                        'name' => $document->name,
                        'type' => $document->type,
                        'storage' => $document->storage,
                        'category' => $document->category,
                        'uploaded_method' => $document->uploaded_method,
                        'document_preview' =>  $document->document_preview = asset($document->document_preview),
                        'pages' => $groupedResults[$document->id]['pages'] ?? []
                    ];
                    // }
                }

                return response()->json($finalResults);
                // $documents = Documents::select('id', 'name', 'type', 'storage', 'category', 'description', 'meta_tags', 'uploaded_method', 'document_preview', 'attributes')
                //     ->orderBy('id', 'DESC')
                //     ->where(function ($query) {
                //         $query->where('is_indexed', '!=', 1)
                //             ->orWhereNull('is_indexed');
                //     })
                //     ->where(function ($query) {
                //         $query->where('is_archived', '!=', 1)
                //             ->orWhereNull('is_archived');
                //     })
                //     ->with(['category' => function ($query) {
                //         $query->select('id', 'category_name');
                //     }]);

                // if ($request->has('term') && $request->term) {
                //     $term = $request->term;
                //     $documents = $documents->where(function ($query) use ($term) {
                //         $query->where('documents.name', 'LIKE', "%$term%")
                //             ->orWhere('documents.description', 'LIKE', "%$term%")
                //             ->orWhereRaw("JSON_CONTAINS(meta_tags, '" . json_encode($term) . "')")
                //             ->orWhereRaw("EXISTS (SELECT 1 FROM JSON_TABLE(documents.attributes, '$[*]' COLUMNS (attribute_value VARCHAR(255) PATH '$.value')) AS attr_table WHERE attr_table.attribute_value LIKE ?)", ["%$term%"]);
                //     });
                // }

                // $documents = $documents->get();

                // foreach ($documents as $document) {
                //     $created_data = DocumentAuditTrial::where('changed_source', $document->id)
                //         ->where('operation', 'document added')
                //         ->first();

                //     if ($created_data) {
                //         $created_by_details = UserDetails::where('user_id', $created_data->user)->first();
                //         $created_by = $created_by_details ? $created_by_details->first_name . ' ' . $created_by_details->last_name : 'Unknown User';
                //         $created_date = $created_data->date_time;
                //     } else {
                //         $created_by = 'Unknown User';
                //         $created_date = 'Unknown Date';
                //     }

                //     $document->created_by = $created_by;
                //     $document->created_date = $created_date;

                //     if ($document->uploaded_method === 'ftp') {
                //         $ftp_name = FTPAccounts::where('id', $document->storage)->value('name');
                //         $document->storage = $ftp_name ?? 'Unknown FTP Storage';
                //     }

                //     $document->document_preview = $document->document_preview ? asset($document->document_preview) : asset('uploads/document_previews/default.png');
                // }

                // return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function deleted_documents(Request $request)
    {
        try {
            if ($request->isMethod('get')) {

                $documents = Documents::onlyTrashed()
                    ->select('id', 'name', 'type', 'storage', 'category', 'uploaded_method', 'document_preview')
                    ->orderBy('id', 'DESC')
                    ->with(['category' => function ($query) {
                        $query->select('id', 'category_name');
                    }])
                    ->get();

                $documentIds = $documents->pluck('id');

                // Get all relevant audit records at once
                $auditData = DocumentAuditTrial::whereIn('changed_source', $documentIds)
                    ->where('operation', 'document added')
                    ->get(['changed_source', 'user', 'date_time']);

                // Get all users at once
                $userIds = $auditData->pluck('user')->unique();
                $users = UserDetails::whereIn('user_id', $userIds)->get()->keyBy('user_id');
                $ftpIds = $documents->where('uploaded_method', 'ftp')->pluck('storage')->unique();
                $ftpAccounts = FTPAccounts::whereIn('id', $ftpIds)->get()->keyBy('id');
                // Map audit and user data to documents
                foreach ($documents as $document) {
                    $created_data = $auditData->firstWhere('changed_source', $document->id);

                    if ($created_data && isset($users[$created_data->user])) {
                        $userDetails = $users[$created_data->user];
                        $document->created_by = $userDetails->first_name . ' ' . $userDetails->last_name;
                        $document->created_date = $created_data->date_time;
                    } else {
                        $document->created_by = 'Unknown User';
                        $document->created_date = 'Unknown Date';
                    }

                    // FTP storage
                    if ($document->uploaded_method === 'ftp') {
                        $document->storage = $ftpAccounts[$document->storage]->name ?? 'Unknown FTP Storage';
                    }

                    // Document preview
                    $document->document_preview = $document->document_preview
                        ? asset($document->document_preview)
                        : asset('uploads/document_previews/default.png');
                }

                return response()->json($documents);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function restore_deleted_document($id, Request $request)
    {

        try {
            $document = Documents::withTrashed()->find($id);

            if ($document) {
                $document->restore();

                $userId = auth('api')->id();

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document restored from delete', 'document', $userId, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Document Restored'
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
    public function delete_document_permanently($id, Request $request)
    {

        try {
            $document = Documents::withTrashed()->find($id);

            if (!$document) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Document not found'
                ], 404);
            }
            if ($document->uploaded_method == 'direct' || $document->uploaded_method == 'bulk') {
                if ($document->file_path) {
                    Storage::delete($document->file_path);
                }

                if ($document->document_preview && $document->document_preview !== 'uploads/document_previews/default.png') {
                    $previewPath = public_path($document->document_preview);
                    if (file_exists($previewPath)) {
                        unlink($previewPath);
                    }
                }

                DocumentVersions::where('document_id', $document->id)->delete();

                DocumentSharedRoles::where('document_id', $document->id)->delete();
                DocumentSharedUsers::where('document_id', $document->id)->delete();

                $document->forceDelete();

                $userId = auth('api')->id();

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document permanently deleted', 'document', $userId, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Document and related files deleted permanently.'
                ], 200);
            }
            if ($document->uploaded_method == 'ftp') {
                $ftp_account = Categories::where('id', $document->category)->value('ftp_account');
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


                if ($document->file_path) {
                    $disk = Storage::disk('dynamic_ftp');
                    if ($disk->exists($document->file_path)) {
                        $disk->delete($document->file_path);
                    }
                }
                if ($document->document_preview && $document->document_preview !== 'uploads/document_previews/default.png') {
                    $previewPath = public_path($document->document_preview);
                    if (file_exists($previewPath)) {
                        unlink($previewPath);
                    }
                }

                DocumentVersions::where('document_id', $document->id)->delete();

                DocumentSharedRoles::where('document_id', $document->id)->delete();
                DocumentSharedUsers::where('document_id', $document->id)->delete();

                $document->forceDelete();

                $userId = auth('api')->id();

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document permanently deleted', 'document', $userId, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Document and related files deleted permanently.'
                ], 200);
            }
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function bulk_delete_documents(Request $request)
    {

        try {
            $documents = json_decode($request->input('documents'), true);
            foreach ($documents as $document) {
                Documents::where('id', '=', $document)->delete();

                $userId = auth('api')->id();

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('document deleted', 'document', $userId, $document, $date_time, null, null);
            }

            return response()->json([
                'status' => "success",
                'message' => 'Documents Deleted'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function bulk_permanently_delete_documents(Request $request)
    {

        try {
            $documents = json_decode($request->input('documents'), true);
            foreach ($documents as $document) {
                $document = Documents::withTrashed()->find($document);

                if ($document->uploaded_method == 'direct' || $document->uploaded_method == 'bulk') {
                    if ($document->file_path) {
                        Storage::delete($document->file_path);
                    }

                    if ($document->document_preview && $document->document_preview !== 'uploads/document_previews/default.png') {
                        $previewPath = public_path($document->document_preview);
                        if (file_exists($previewPath)) {
                            unlink($previewPath);
                        }
                    }

                    DocumentVersions::where('document_id', $document->id)->delete();

                    DocumentSharedRoles::where('document_id', $document->id)->delete();
                    DocumentSharedUsers::where('document_id', $document->id)->delete();

                    $document->forceDelete();

                    $userId = auth('api')->id();

                    $date_time = Carbon::now()->format('Y-m-d H:i:s');
                    $auditFunction = new CommonFunctionsController();
                    $auditFunction->document_audit_trail('document permanently deleted', 'document', $userId, $document, $date_time, null, null);
                }
                if ($document->uploaded_method == 'ftp') {
                    $ftp_account = Categories::where('id', $document->category)->value('ftp_account');
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


                    if ($document->file_path) {
                        $disk = Storage::disk('dynamic_ftp');
                        if ($disk->exists($document->file_path)) {
                            $disk->delete($document->file_path);
                        }
                    }
                    if ($document->document_preview && $document->document_preview !== 'uploads/document_previews/default.png') {
                        $previewPath = public_path($document->document_preview);
                        if (file_exists($previewPath)) {
                            unlink($previewPath);
                        }
                    }

                    DocumentVersions::where('document_id', $document->id)->delete();

                    DocumentSharedRoles::where('document_id', $document->id)->delete();
                    DocumentSharedUsers::where('document_id', $document->id)->delete();

                    $document->forceDelete();

                    $userId = auth('api')->id();

                    $date_time = Carbon::now()->format('Y-m-d H:i:s');
                    $auditFunction = new CommonFunctionsController();
                    $auditFunction->document_audit_trail('document permanently deleted', 'document', $userId, $document, $date_time, null, null);
                }
            }

            return response()->json([
                'status' => "success",
                'message' => 'Documents Permanently Deleted'
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function search(Request $request)
    {
        $query = $request->input('query');

        $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
        $index = $client->index('python_test_docs_3');

        // $searchResults = $index->search($query);

        $searchResults = $index->search($query, [
            'limit' => 20,
            'attributesToHighlight' => ['content'],
            'showMatchesPosition' => true,
        ]);

        return response()->json($searchResults->getHits());
    }
    public function get_document_text($doc_id, Request $request)
    {
        $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
        $index = $client->index('python_test_docs_3');

        $results = $index->search('', [
            'filter' => ["sql_document = $doc_id"]
        ]);

        return $results->getHits();
    }
    public function setFilterableAttributes()
    {
        $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
        $index = $client->index('python_test_docs_3');

        $index->updateFilterableAttributes(['sql_document', 'content']);
        $filterables = $index->getFilterableAttributes();
        return $filterables;
    }
}
