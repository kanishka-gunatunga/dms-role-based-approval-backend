<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use File;
use Mail;
use PDF;
use Image;
use Redirect;
use Session;
use Carbon\Carbon;
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
use App\Models\DocumentAuditTrial;
use App\Models\ADCredential;


class CommonFunctionsController extends Controller
{
    public function document_audit_trail($operation,$type, $user, $changed_source, $date_time, $roles, $users)
    {
            
            $audit = new DocumentAuditTrial();
            $audit->operation = $operation;
            $audit->type = $type;
            $audit->user = $user;
            $audit->changed_source = $changed_source;
            $audit->date_time = $date_time;
            $audit->assigned_roles = $roles;
            $audit->assigned_users = $users;
            $audit->save();


    }

    public function encryptFile($filePath, $encryptionKey, $cipher)
    {
    $ivLength = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivLength);

    $fileContent = file_get_contents($filePath);
    $encryptedContent = openssl_encrypt($fileContent, $cipher, $encryptionKey, 0, $iv);

    return [
        'encrypted_content' => $encryptedContent,
        'iv' => base64_encode($iv)
    ];
    }
    public function updateFileEncryption($filePath, $currentKey, $currentCipher, $newKey, $newCipher)
    {
        try {
        
            $absolutePath = storage_path('app/' . $filePath);
            $encryptedFilePath = $absolutePath . '.enc';
            $ivFilePath = $absolutePath . '.iv';
    
            if (!file_exists($encryptedFilePath) || !file_exists($ivFilePath)) {
                throw new \Exception("Encrypted file or IV file not found.");
            }
    
            $iv = file_get_contents($ivFilePath);

            $encryptedContent = file_get_contents($encryptedFilePath);
            $decryptedContent = $this->decryptContent($encryptedContent, $currentKey, $currentCipher, $iv);
    
            $newEncryptionResult = $this->encryptContent($decryptedContent, $newKey, $newCipher);
    
            file_put_contents($encryptedFilePath, $newEncryptionResult['encrypted_content']);
            file_put_contents($ivFilePath, $newEncryptionResult['iv']);
    
            return response()->json([
                'status' => 'success',
                'message' => 'File encryption updated successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Failed to update encryption.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function decryptFile($encryptedContent, $iv, $encryptionKey, $cipher)
    {
    $iv = base64_decode($iv);
    return openssl_decrypt($encryptedContent, $cipher, $encryptionKey, 0, $iv);
    }


function getAccessToken()
{
    try {
    $credential_details = ADCredential::first();
    $tenantId = $credential_details->tenant_id; 
    $clientId = $credential_details->client_id; 
    $clientSecret = $credential_details->client_secret; 
    
    $response = Http::withoutVerifying()->asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
    ]);


    if ($response->successful()) {
        return $response->json()['access_token'];
    }
    }
    catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

function getUsers()
{
    try {
    $accessToken = $this->getAccessToken();

    $response = Http::withoutVerifying()->withToken($accessToken)
        ->get('https://graph.microsoft.com/v1.0/users');

        return $response->json();

}
catch (\Exception $e) {
    return response()->json([
        'status' => "fail",
        'message' => 'Request failed',
        'error' => $e->getMessage()
    ], 500);
}
}

 function authenticateWithAzureAD($email, $password)
{
    
    $credential_details = ADCredential::first();
    $tenantId = $credential_details->tenant_id; 
    $clientId = $credential_details->client_id; 
    $clientSecret = $credential_details->client_secret; 

    $url = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

    try {
        $response = Http::asForm()->post($url, [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'username' => $email,
            'password' => $password,
        ]);

        if ($response->successful()) {
            return [
                'authenticated' => true,
                'access_token' => $response->json()['access_token'],
            ];
        }

        return [
            'authenticated' => false,
            'error' => $response->json(),
        ];
    } catch (\Exception $e) {
        return [
            'authenticated' => false,
            'error' => $e->getMessage(),
        ];
    }
}



public function retreive_document($document,$user_details,$isdownload)
{
    $date_time = Carbon::now()->format('Y-m-d H:i:s');
        
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

        
        $disk = Storage::disk('dynamic_ftp');
        $filePath = $document->file_path;

        $localDisk = Storage::disk('local');
        $localDisk->put($filePath, $disk->get($filePath));

        $file_type = $document->type;
        $isImage = in_array($file_type, ['jpeg', 'png', 'gif', 'webp', 'jpg']);
        if($isdownload == false){
        if ($isImage) {
            $manager = new ImageManager(new Driver());
        
            $image = $manager->read($localDisk->path($filePath));
            $watermarkTexts = ['Confidential', 'Do Not Copy', $user_details->first_name.' '.$user_details->last_name, $date_time];
        
    
            $fontSize = max(16, min(0.05 * $image->height(), 48)); 
        
            foreach ($watermarkTexts as $key => $text) {
                $image->text(
                    $text,
                    $image->width() / 2,
                    ($image->height() / 2) + ($key * $fontSize * 1.5), 
                    function ($font) use ($fontSize) {
                        $font->filename(storage_path('fonts/roboto.ttf'));
                        $font->size($fontSize); 
                        $font->color('rgba(255, 255, 255, 0.64)');
                        $font->align('center');
                        $font->valign('middle');
                    }
                );
            }
        
            $image->save($localDisk->path($filePath));
        }
        }

        $tempUrl = $localDisk->temporaryUrl($filePath, now()->addHour());

        return $tempUrl;
    }
    else {
   
            $filePath = $document->file_path;

            $file_type = $document->type;
            $isImage = in_array($file_type, ['jpeg', 'png', 'gif', 'webp', 'jpg']);
            if($isdownload == false){
                if ($isImage) {

                    $manager = new ImageManager(new Driver());
                    $image = $manager->read(storage_path('app/private/' . $filePath));
    
                    $watermarkTexts = ['Confidential', 'Do Not Copy', $user_details->first_name . ' ' . $user_details->last_name, $date_time];
    
                    $fontSize = max(16, min(0.05 * $image->height(), 48)); 
            
                    foreach ($watermarkTexts as $key => $text) {
                        $image->text(
                            $text,
                            $image->width() / 2,
                            ($image->height() / 2) + ($key * $fontSize * 1.5), 
                            function ($font) use ($fontSize) {
                                $font->filename(storage_path('fonts/roboto.ttf'));
                                $font->size($fontSize); 
                                $font->color('rgba(255, 255, 255, 0.64)');
                                $font->align('center');
                                $font->valign('middle');
                            }
                        );
                    }
    
                    $watermarkFilePath = 'watermarks/' . basename($filePath);
                    $image->save(storage_path('app/private/' . $watermarkFilePath));
                    $tempUrl = Storage::disk('local')->temporaryUrl(
                        $watermarkFilePath, 
                        now()->addHour()
                    );
                }
                else{
                    $tempUrl = Storage::disk('local')->temporaryUrl(
                        $filePath, 
                        now()->addHour()
                    ); 
                }
            }
           
            else{
                $tempUrl = Storage::disk('local')->temporaryUrl(
                    $filePath, 
                    now()->addHour()
                ); 
            }

            return $tempUrl;

        
    }
}

// public function get_file_text_content($type,$file_extension,$filePath)
//     {
//         if($type == 'direct'){

//             try {
//                 $content = [];
//                 if ($file_extension === 'pdf') {
//                     $parser = new Parser();
//                     // $pdf = $parser->parseFile(storage_path("app/private/$filePath"));
//                     // $content = $pdf->getText();
//                     // $content = preg_replace('/\s+/', ' ', trim($content));
//                     $pdf = $parser->parseFile(storage_path("app/private/$filePath"));
//                     foreach ($pdf->getPages() as $index => $page) {
//                         $content[$index + 1] = preg_replace('/\s+/', ' ', trim($page->getText()));
//                     }
//                 } 
//                 elseif ($file_extension === 'docx') {
//                     // $phpWord = WordIOFactory::load(storage_path("app/private/$filePath"));
//                     // $content = '';
//                     // foreach ($phpWord->getSections() as $section) {
//                     //     foreach ($section->getElements() as $element) {
//                     //         if (method_exists($element, 'getText')) {
//                     //             $content .= $element->getText() . ' ';
//                     //         }
//                     //     }
//                     // }
//                     // $content = preg_replace('/\s+/', ' ', trim($content));
//                     $phpWord = WordIOFactory::load(storage_path("app/private/$filePath"));
//                     $content = []; 

//                     try {
//                         foreach ($phpWord->getSections() as $index => $section) {
//                             $sectionContent = '';
//                             foreach ($section->getElements() as $element) {
//                                 if (method_exists($element, 'getText')) {
//                                     $sectionContent .= $element->getText() . ' ';
//                                 } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
//                                     foreach ($element->getElements() as $textElement) {
//                                         if (method_exists($textElement, 'getText')) {
//                                             $sectionContent .= $textElement->getText() . ' ';
//                                         }
//                                     }
//                                 }
//                             }
//                             $content[$index + 1] = preg_replace('/\s+/', ' ', trim($sectionContent));
//                         }
//                     } catch (\Exception $e) {
//                         $content = []; // Return empty content if any error occurs
//                     }

//                     return $content;
//                 }
//                 elseif ($file_extension === 'xlsx') {
//                     // $spreadsheet = SpreadsheetIOFactory::load(storage_path("app/private/$filePath"));
//                     // $sheet = $spreadsheet->getActiveSheet();
//                     // $content = '';
//                     // foreach ($sheet->getRowIterator() as $row) {
//                     //     foreach ($row->getCellIterator() as $cell) {
//                     //         $content .= $cell->getFormattedValue() . ' ';
//                     //     }
//                     // }
//                     // $content = preg_replace('/\s+/', ' ', trim($content));
//                     $spreadsheet = SpreadsheetIOFactory::load(storage_path("app/private/$filePath"));
//                     foreach ($spreadsheet->getAllSheets() as $sheetIndex => $sheet) {
//                         $sheetContent = '';
//                         foreach ($sheet->getRowIterator() as $row) {
//                             foreach ($row->getCellIterator() as $cell) {
//                                 $sheetContent .= $cell->getFormattedValue() . ' ';
//                             }
//                         }
//                         $content[$sheetIndex + 1] = preg_replace('/\s+/', ' ', trim($sheetContent));
//                     }
//                 }
//                 elseif ($file_extension === 'pptx') {
//                     // $presentation = PresentationIOFactory::load(storage_path("app/private/$filePath"));
//                     // $content = '';
        
//                     // foreach ($presentation->getAllSlides() as $slide_k => $slide_v) {
//                     //     $shapes = $slide_v->getShapeCollection();
//                     //     foreach ($shapes as $shape_k => $shape_v) {
//                     //         if ($shape_v instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
//                     //             $paragraphs = $shape_v->getParagraphs();
//                     //             foreach ($paragraphs as $paragraph_v) {
//                     //                 $text_elements = $paragraph_v->getRichTextElements();
//                     //                 foreach ($text_elements as $text_element_v) {
//                     //                     $content .= $text_element_v->getText() . ' ';
//                     //                 }
//                     //             }
//                     //         }
//                     //     }
//                     // }
            
//                     // $content = preg_replace('/\s+/', ' ', trim($content));
                    
//                     $presentation = PresentationIOFactory::load(storage_path("app/private/$filePath"));
//                     foreach ($presentation->getAllSlides() as $slideIndex => $slide) {
//                         $slideContent = '';
//                         $shapes = $slide->getShapeCollection();
//                         foreach ($shapes as $shape) {
//                             if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
//                                 $paragraphs = $shape->getParagraphs();
//                                 foreach ($paragraphs as $paragraph) {
//                                     $textElements = $paragraph->getRichTextElements();
//                                     foreach ($textElements as $textElement) {
//                                         $slideContent .= $textElement->getText() . ' ';
//                                     }
//                                 }
//                             }
//                         }
//                         $content[$slideIndex + 1] = preg_replace('/\s+/', ' ', trim($slideContent));
//                     }
//                 }
//                 elseif ($file_extension === 'txt') {
//                     // $content = file_get_contents(storage_path("app/private/$filePath"));
//                     // $content = preg_replace('/\s+/', ' ', trim($content));
//                     $textContent = file_get_contents(storage_path("app/private/$filePath"));
//                     $content[1] = preg_replace('/\s+/', ' ', trim($textContent));
//                 }
//                 else{
//                     $content = '';
//                 }
//             }
//             catch (\Exception $e) {
//                 $content = '';
//             }
//             return $content;
//         }
           


//     }
public function get_file_text_content($type, $file_extension, $filePath)
{
    if ($type == 'direct') {
        $content = [];

        try {
            if ($file_extension === 'pdf') {
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile(storage_path("app/private/$filePath"));
                    foreach ($pdf->getPages() as $index => $page) {
                        $content[$index + 1] = preg_replace('/\s+/', ' ', trim($page->getText()));
                    }
                } catch (\Exception $e) {
                    $content = [];
                }
            } 
            elseif ($file_extension === 'docx') {
                try {
                    $phpWord = WordIOFactory::load(storage_path("app/private/$filePath"));
                    foreach ($phpWord->getSections() as $index => $section) {
                        $sectionContent = '';
                        foreach ($section->getElements() as $element) {
                            if (method_exists($element, 'getText')) {
                                $sectionContent .= $element->getText() . ' ';
                            } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                                foreach ($element->getElements() as $textElement) {
                                    if (method_exists($textElement, 'getText')) {
                                        $sectionContent .= $textElement->getText() . ' ';
                                    }
                                }
                            }
                        }
                        $content[$index + 1] = preg_replace('/\s+/', ' ', trim($sectionContent));
                    }
                } catch (\Exception $e) {
                    $content = [];
                }
            }
            elseif ($file_extension === 'xlsx') {
                try {
                    $spreadsheet = SpreadsheetIOFactory::load(storage_path("app/private/$filePath"));
                    foreach ($spreadsheet->getAllSheets() as $sheetIndex => $sheet) {
                        $sheetContent = '';
                        foreach ($sheet->getRowIterator() as $row) {
                            foreach ($row->getCellIterator() as $cell) {
                                $sheetContent .= $cell->getFormattedValue() . ' ';
                            }
                        }
                        $content[$sheetIndex + 1] = preg_replace('/\s+/', ' ', trim($sheetContent));
                    }
                } catch (\Exception $e) {
                    $content = [];
                }
            }
            elseif ($file_extension === 'pptx') {
                try {
                    
                    $presentation = PresentationIOFactory::load(storage_path("app/private/$filePath"));
                    $content = [];
                
                    foreach ($presentation->getAllSlides() as $slideIndex => $slide) {
                        $slideContent = '';
                        $shapes = $slide->getShapeCollection();
                
                        foreach ($shapes as $shape) {
                            if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                                foreach ($shape->getParagraphs() as $paragraph) {
                                    foreach ($paragraph->getRichTextElements() as $textElement) {
                                        if (method_exists($textElement, 'getText')) {
                                            $slideContent .= $textElement->getText() . ' ';
                                        }
                                    }
                                }
                            }
                        }
                
                        $content[$slideIndex + 1] = preg_replace('/\s+/', ' ', trim($slideContent));
                    }
                } catch (\Exception $e) {
                    $content = [];
                }
                    
            }
            elseif ($file_extension === 'txt') {
                try {
                    $textContent = file_get_contents(storage_path("app/private/$filePath"));
                    $content[1] = preg_replace('/\s+/', ' ', trim($textContent));
                } catch (\Exception $e) {
                    $content = [];
                }
            } 
            else {
                $content = [];
            }
        } 
        catch (\Exception $e) {
            $content = [];
        }

        return $content;
    }
}

public function deleteDocumentFromIndex($documentId)
{
    $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
    $index = $client->index('python_test_docs_3');

    $searchResults = $index->search('', [
        'filter' => ["sql_document = $documentId"]
    ]);

    if (!empty($searchResults->getHits())) {
        $idsToDelete = array_map(fn($doc) => $doc['id'], $searchResults->getHits());
        $index->deleteDocuments($idsToDelete);
    }
}
public function indexDocumentContent($documentId, $content)
{
    if (empty($content)) {
        return; 
    }

    $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
    $index = $client->index('python_test_docs_3');

    $meiliData = [];
    // $index->updateSettings([ // 'filterableAttributes' => ['content'], // ]);
     $index->updateSettings([ 
                    'filterableAttributes' => ['content','sql_document','page'],  
                ]);
    foreach ($content as $page) {
        $pageNumber = $page['page'] ?? 1;
        $pageText = $page['text'] ?? '';

        $meiliData[] = [
            'id' => $documentId . '-' . $pageNumber,
            'sql_document' => $documentId,
            'page' => $pageNumber,
            'content' => $pageText
        ];
    }

    $index->addDocuments($meiliData);
}

public function sendToNode($filePath)
    {
        try {
            $response = Http::attach(
                'document',
                file_get_contents($filePath),
                basename($filePath)
            )->post('https://dms-parser-node.vercel.app/api/extract-text-pages');

            if ($response->successful()) {
                return $response->json();
            } else {
                return $response->json();
            }
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    public function sendToNodeGetFullText($filePath)
    {
        try {
            $response =Http::withOptions(['verify' => false])->attach(
                'document',
                file_get_contents($filePath),
                basename($filePath)
            )->post('https://dms-parser-node.vercel.app/api/extract-text');

            if ($response->successful()) {
                return $response->json();
            } else {
                return $response->json();
            }
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    public function retreive_document_path($document)
    {
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

            return storage_path("app/private/$final_file_path");

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

