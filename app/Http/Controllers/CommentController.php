<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\GeneralProvidentFund;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * Store and attach a comment to a General Provident Fund record.
     */
    public function storeForGpf(Request $request, GeneralProvidentFund $generalProvidentFund)
    {
        $validated = $request->validate([
            'comment' => 'required|string|max:2000',
            'purpose' => 'nullable|string|max:100',
        ]);

        $comment = Comment::create([
            'user_id' => auth()->id() ?? $generalProvidentFund->user_id,
            'purpose' => $validated['purpose'] ?? 'gpf_note',
            'comment' => $validated['comment'],
        ]);

        $generalProvidentFund->comments()->syncWithoutDetaching([$comment->id]);

        return response()->json([
            'status' => 200,
            'message' => 'Comment attached successfully.',
            'data' => $comment->load('user'),
        ], 201);
    }

    /**
     * Get all comments for a GPF record.
     */
    public function indexForGpf(GeneralProvidentFund $generalProvidentFund)
    {
        return response()->json($generalProvidentFund->comments()->with('user')->orderBy('id', 'desc')->get());
    }

    /**
     * Delete a comment.
     */
    public function destroy(Comment $comment)
    {
        $comment->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Comment deleted successfully.',
        ]);
    }
}
