<?php
namespace App\Repositories\Frontend;

use App\Mail\RegisterOrder;
use App\Models\EmailRecord;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class SignupRepository extends BaseRepository
{
    public function uploadFace($input)
    {
        $oldImagesName      = $input->getClientOriginalName();
        $imageExtensionName = $input->getClientOriginalExtension();
        $imageSize          = $input->getSize() / 1024; // 单位为KB
        if (!in_array(strtolower($imageExtensionName), ['jpeg', 'jpg', 'gif', 'gpeg', 'png'])) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '请上传正确的图片',
            ];
        }
        if ($imageSize > config('app.pictureSize')) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '上传的图片不得大于500KB',
            ];
        }
        $newImagesName = md5(time()) . random_int(5, 5) . "." . $imageExtensionName;
        $filedir       = "images/"; // 图片上传路径
        $uploadResult  = $input->move($filedir, $newImagesName);
        return [
            'status'  => !empty($uploadResult) ? Parent::SUCCESS_STATUS : Parent::ERROR_STATUS,
            'data'    => [
                'pathName' => !empty($uploadResult) ? $filedir : '',
                'fileName' => !empty($uploadResult) ? $newImagesName : '',
            ],
            'message' => !empty($uploadResult) ? '头像上传成功' : '头像上传失败',
        ];
    }

    public function createUser($input)
    {
        $usernameUniqueData = User::where('username', $input['username'])->first();
        if (!empty($usernameUniqueData)) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '用户用户名已经存在',
            ];
        }
        $emailUniqueData = User::where('email', $input['email'])->first();
        if (!empty($emailUniqueData)) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '用户邮箱已经存在',
            ];
        }
        $insertResult = User::create([
            'username' => $input['username'],
            'email'    => $input['email'],
            'face'     => $input['face'],
            'password' => md5($input['password'] . config('app.passwordEncrypt')),
            'active'   => 0,
            'status'   => 1,
        ]);
        if (!$insertResult) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '注册失败，未知错误',
            ];
        }
        $insertEmailResult = EmailRecord::create([
            'type_id'     => Parent::getDicts(['email_type'])['email_type']['register_active'],
            'user_id'     => $insertResult->id,
            'email_title' => '账户激活邮件',
            'text'        => '用户首次注册',
            'status'      => 1,
        ]);
        $mailData = [
            'title' => '账户激活邮件',
            'name'  => $insertResult->username,
            'url'   => env('APP_URL') . '/active?mail_id=' . $insertEmailResult->id . '&user_id=' . base64_encode($insertResult->id),
        ];
        Mail::to($insertResult->email)->send(new RegisterOrder($mailData));
        return [
            'status'  => Parent::SUCCESS_STATUS,
            'data'    => [
                'id'       => base64_encode($insertResult->id),
                'username' => $insertResult->username,
                'email'    => $insertResult->email,
            ],
            'message' => '注册成功，请于一小时内激活账号',
        ];
    }

    public function activeUser($input)
    {
        $emailId = isset($input['email_id']) && !empty($input['email_id']) ? intval($input['email_id']) : '';
        $userId = isset($input['user_id']) && !empty($input['user_id']) ? intval(base64_decode($input['user_id'])) : '';
        if (!$emailId || !$userId) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '不存在这个链接地址或邮件已经过期',
            ];
        }
        // 判断邮件是否过期
        $emailList = EmailRecord::where([
            ['id', '=', $emailId],
            ['user_id', '=', $userId]
        ])->first();
        if (empty($emailList) || time() - config('APP_EMAIL_REGISTER_TIME') < strtotime($emailList->create_at)) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '不存在这个链接地址或邮件已经过期',
            ];
        }
        $userList = User::where('id', $userId)->first();
        if (empty($userList) || $userList->active) {
            return [
                'status'  => Parent::ERROR_STATUS,
                'data'    => [],
                'message' => '不存在此用户',
            ];
        }
        // 激活
        $userList->active = 1;
        $saveResult = $userList->save();
        return [
            'status'  => Parent::SUCCESS_STATUS,
            'data'    => [],
            'message' => '用户成功激活',
        ];
    }
}