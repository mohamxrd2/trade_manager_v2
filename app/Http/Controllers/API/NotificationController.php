<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $unreadOnly = $request->boolean('unread_only', false);

            $query = Notification::where('user_id', $user->id)
                ->with('article:id,name,type')
                ->orderBy('created_at', 'desc');

            if ($unreadOnly) {
                $query->where('read', false);
            }

            $notifications = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Notifications récupérées avec succès',
                'data' => [
                    'notifications' => $notifications->items(),
                    'pagination' => [
                        'current_page' => $notifications->currentPage(),
                        'per_page' => $notifications->perPage(),
                        'total' => $notifications->total(),
                        'last_page' => $notifications->lastPage(),
                    ],
                    'unread_count' => Notification::where('user_id', $user->id)
                        ->where('read', false)
                        ->count(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(string $id): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification non trouvée'
                ], 404);
            }

            $notification->update(['read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Notification marquée comme lue',
                'data' => $notification
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $count = Notification::where('user_id', $user->id)
                ->where('read', false)
                ->update(['read' => true]);

            return response()->json([
                'success' => true,
                'message' => "{$count} notification(s) marquée(s) comme lue(s)",
                'count' => $count
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a notification.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification non trouvée'
                ], 404);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification supprimée avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unread count.
     */
    public function unreadCount(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $count = Notification::where('user_id', $user->id)
                ->where('read', false)
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'unread_count' => $count
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du nombre de notifications non lues',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
