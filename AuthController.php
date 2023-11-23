<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\ExamPayments;
use App\Models\Package;
use App\Models\Question;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Exam;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

use App\Models\PasswordReset;
// use Mail;
use Illuminate\Support\Facades\Mail;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;



class AuthController extends Controller
{
    //
    public function loadRegister()
    {
        if(Auth::user() && Auth::user()->is_admin == 1)
        {
            return redirect('/admin/dashboard');
        }
        else if(Auth::user() && Auth::user()->is_admin == 0)
        {
            return redirect('/dashboard');
        }
        return view('register');
    }

    public function studentRegister(Request $request)
    {
        $request->validate([
            'name' => 'string|required|min:2',
            'email' => 'string|email|required|max:100|unique:users',
            'password' => 'string|required|confirmed|min:4'
        ]);

        $user = new User;
        $user->name = $request->name;
        $user->email = $request->email;
        $user->password = Hash::make($request->password);
        $user->save();

        return back()->with('success', 'Registeration Confirmed!');

    }

    public function loadLogin()
    {
        if(Auth::user() && Auth::user()->is_admin == 1)
        {
            return redirect('/admin/dashboard');
        }
        else if(Auth::user() && Auth::user()->is_admin == 0)
        {
            return redirect('/dashboard');
        }
        return view('login');
    }

    public function userLogin (Request $request)
    {
        $request->validate([
            'email' => 'string|required|email',
            'password' => 'string|required'
        ]);
        
        $userCredential = $request->only('email', 'password');
        
        if(Auth::attempt ($userCredential)) {
            if(Auth::user()->is_admin == 1){
                return redirect('/admin/dashboard');
            }
            else {
                return redirect('/dashboard');
            }
        }
        else{
            return back()->with('error', 'Username & Password is incorrect');
        }
    }

    public function loadDashboard()
    {
        $exams = Exam::where('plan',0)->with('subjects')->orderBy('date','DESC')->get();
        //where('plan',0)->
        return view('student.dashboard',['exams'=>$exams]);
    }

    public function adminProfile()
    {
        $userData = Auth::user();
        return view('admin.profile', compact('userData'));
    }

    // public function editProfile(Request $request)
	// {
	// 	try {
    //         if ($request->hasFile('profile_pic')) {
    //             $file = $request->file('profile_pic');
    //             $filename = $file->getClientOriginalName();
    //             Log::info('Uploaded File Name: ' . $filename);
    
    //             // Store the uploaded file
    //             $file->storeAs('profile_pictures', $filename, 'public');
    
    //             // Update the 'profile_pic' field in the user model
    //             $user = auth()->user();
    //             $user->profile_pic = 'profile_pictures/' . $filename; // Update the path as needed
    //             $user->save();
    //         }
	// 		return response()->json(['success' => true, 'msg' => "Profile Updated Successfully!"]);
	// 	} catch (\Exception $e) {
	// 		return response()->json(['success' => false, 'msg' => $e->getMessage()]);
	// 	}
	// }

    public function editProfile(Request $request)
    {
        try {
            // Retrieve the current user
            $user = auth()->user();

            // Retrieve the current path to the profile picture
            $currentProfilePicPath = $user->profile_pic;

            if ($request->hasFile('profile_pic')) {
                $file = $request->file('profile_pic');
                $filename = $file->getClientOriginalName();
                Log::info('Uploaded File Name: ' . $filename);

                // Store the uploaded file
                $newProfilePicPath = $file->storeAs('profile_pictures', $filename, 'public');

                // Update the 'profile_pic' field in the user model
                $user->profile_pic = $newProfilePicPath;
                $user->save();

                // Delete old profile picture after successful update
                $this->deleteOldProfilePicture($currentProfilePicPath);
            }

            return response()->json(['success' => true, 'msg' => 'Profile Updated Successfully!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    private function deleteOldProfilePicture($currentProfilePicPath)
    {
        // Delete old profile picture if it exists and is not equal to the current user's new profile picture path
        if ($currentProfilePicPath && $currentProfilePicPath !== 'profile_pictures/default.png') {
            Storage::disk('public')->delete($currentProfilePicPath);
        }
    }





    public function adminDashboard()
    {
        // Get the counts
        $subjectCount = Subject::count();
        $examCount = Exam::count();
        $packageCount = Package::count();
        $questionCount = Question::count();
        $examReviewedCount = ExamAttempt::where('status', 1)->count();
        $studentCount = User::where('is_admin', 0)->count();
        $paymentCount = ExamPayments::count();

        //Chart-1 Data
        $subjects = Subject::pluck('sub_code')->toArray(); // Get an array of subject IDs
        $subjectsWithExamCount = Subject::select('subjects.id as subject_id', DB::raw('count(exams.id) as exam_count'))
            ->leftJoin('exams', 'subjects.id', '=', 'exams.subject_id')
            ->groupBy('subjects.id')
            ->get();

        //Chart-2 Data
        $rankedStudents = DB::table('exams_attempt')
            ->select('users.name as student_name', 'exams.exam_name as exam_name', DB::raw('MAX(exams_attempt.marks) as best_score'))
            ->join('users', 'users.id', '=', 'exams_attempt.user_id')
            ->join('exams', 'exams.id', '=', 'exams_attempt.exam_id')
            ->groupBy('student_name', 'exam_name')
            ->get();
        $chartData = [];
        foreach ($rankedStudents as $student) {
            $studentName = $student->student_name;
            $examName = $student->exam_name;
            
            if (!isset($chartData[$studentName])) {
                $chartData[$studentName] = [];
            }
            
            $chartData[$studentName][$examName] = $student->best_score;
        }

        //Chart-3 Data
        $reviewedCount = DB::table('exams_attempt')->where('status', 1)->count();
        $notReviewedCount = DB::table('exams_attempt')->where('status', 0)->count();

        //Chart-4 Data
        $paidExamsCount = DB::table('exams')->where('plan', 1)->count();
        $freeExamsCount = DB::table('exams')->where('plan', 0)->count();

        //Chart-5 Data
        $allStudents = DB::table('users')->where('is_admin', 0)->select('name as student_name')->get();
        $allExams = DB::table('exams')->select('exam_name')->get();

        $attemptData = DB::table('users')
            ->where('users.is_admin', 0)
            ->crossJoin('exams')
            ->leftJoin('exams_attempt', function ($join) {
                $join->on('exams_attempt.user_id', '=', 'users.id')
                    ->on('exams_attempt.exam_id', '=', 'exams.id');
            })
            ->select('users.name as student_name', 'exams.exam_name as exam_name', DB::raw('count(exams_attempt.id) as attempt_count'))
            ->groupBy('users.name', 'exams.exam_name')
            ->get();

        // Pass the data to your Blade view
        return view('admin.dashboard', compact('subjectCount', 'examCount', 'packageCount', 'questionCount', 'examReviewedCount', 'studentCount', 'paymentCount', 'subjects', 'subjectsWithExamCount', 'chartData', 'reviewedCount', 'notReviewedCount', 'paidExamsCount', 'freeExamsCount', 'allStudents', 'allExams', 'attemptData'));
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        Auth::logout();
        return redirect('/');
    }

    public function forgotPasswordLoad()
    {
        return view('forgot-password');
    }

    public function forgotPassword(Request $request)
    {
        try{
            $user = User::where('email',$request->email)->get();

            if (count ($user) > 0) {
                $token = Str :: random(40);
                $domain = URL :: to('/');
                $url = $domain. '/reset-password?token='.$token;

                $data['url'] = $url;
                $data['email'] = $request->email;
                $data['title'] = 'Password Reset';
                $data['body'] = 'Please click on below link to reset your password. ';

                Mail::send('forgotPasswordMail', ['data'=>$data], function($message) use($data) {
                    $message->to($data['email'])->subject($data['title']);
                });

                $dateTime = Carbon::now()->format('Y-m-d H:i:s');

                // PasswordReset::updateOnCreate(
                //     ['email'=>$request->email],
                //     [
                //         'email' => $request->email,
                //         'token' => $token,
                //         'created_at' => $dateTime
                //     ]
                // );
                PasswordReset::updateOrInsert(
                    ['email' => $request->email],
                    [
                        'email' => $request->email,
                        'token' => $token,
                        'created_at' => $dateTime
                    ]
                );

                return back()->with('success', 'A link has been sent to reset password');
            }
            else{
                return back()->with('error', 'Email is not exists!');
            }
            
        }catch(\Exception $ex){
            return back()->with('error',$ex->getMessage());
        }
    }

    public function resetPasswordLoad(Request $request)
    {
        $resetData = PasswordReset::where('token', $request->token)->get();

        if(isset($request->token) && count($resetData) > 0)
        {
            $user = User::where('email', $resetData[0]['email'])->get();

            return view('resetPassword', compact('user'));
        }
        else{
            return view('404');
        }
    }

    public function resetPassword(Request $request){
        $request->validate([
            'password' => 'required|string|min:4|confirmed'
        ]);

        $user = User::find($request->id);

        $user->password = Hash::make($request->password);
        $user->save();

        PasswordReset::where('email', $user->email)->delete();
        
        //return "<h2>You password has been reset successfully!</h2>";
        //return redirect('/')->with('success', 'Password reset successful. Please log in.');
    }
}
