<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $to = 'support@gexoptions.com';

        Mail::raw(
            "New contact submission:\n\nName: {$data['name']}\nEmail: {$data['email']}\n\nMessage:\n{$data['message']}",
            function ($msg) use ($to) {
                $msg->to($to)->subject('GexOptions Contact Form');
            }
        );

        return back()->with('status', 'contact-sent');
    }
}
