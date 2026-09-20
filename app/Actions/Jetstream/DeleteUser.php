<?php

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Support\AccountDeletionEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Laravel\Jetstream\Features;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        $connectionName = $user->getConnectionName();
        $connection = DB::connection($connectionName);

        $profilePhoto = $connection->transaction(function () use ($connection, $connectionName, $user): ?array {
            $lockedUser = User::on($connectionName)
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The subscriptions index begins with user_id, so this locks the
            // current user's rows (and the matching InnoDB index range) before
            // any deletion decision is made.
            $subscriptions = $lockedUser->subscriptions()
                ->lockForUpdate()
                ->get();

            if (AccountDeletionEligibility::isBlocked($subscriptions)) {
                throw ValidationException::withMessages([
                    'account' => AccountDeletionEligibility::validationMessage(),
                ]);
            }

            foreach ($subscriptions as $subscription) {
                $subscription->items()->delete();
                $subscription->delete();
            }

            $lockedUser->tokens()->delete();

            $connection->table(config('session.table', 'sessions'))
                ->where('user_id', $lockedUser->getKey())
                ->delete();

            $connection->table('password_reset_tokens')
                ->where('email', $lockedUser->email)
                ->delete();

            // This conditional delete catches a subscription row that became
            // visible after the locked snapshot on engines or isolation levels
            // without InnoDB-style next-key locking. Without a database foreign
            // key, a writer already queued on the range can still insert after
            // this transaction commits; every subscription writer must also
            // coordinate on the user row until the schema enforces that invariant.
            $userTable = $lockedUser->getTable();
            $subscriptionTable = $lockedUser->subscriptions()->getRelated()->getTable();
            $deleted = $connection->table($userTable)
                ->where($userTable.'.'.$lockedUser->getKeyName(), $lockedUser->getKey())
                ->whereNotExists(function ($query) use ($subscriptionTable, $userTable, $lockedUser): void {
                    $query->selectRaw('1')
                        ->from($subscriptionTable)
                        ->whereColumn(
                            $subscriptionTable.'.'.$lockedUser->getForeignKey(),
                            $userTable.'.'.$lockedUser->getKeyName(),
                        );
                })
                ->delete();

            if ($deleted !== 1) {
                throw ValidationException::withMessages([
                    'account' => AccountDeletionEligibility::validationMessage(),
                ]);
            }

            if (! Features::managesProfilePhotos() || $lockedUser->profile_photo_path === null) {
                return null;
            }

            return [
                'disk' => isset($_ENV['VAPOR_ARTIFACT_NAME'])
                    ? 's3'
                    : config('jetstream.profile_photo_disk', 'public'),
                'path' => $lockedUser->profile_photo_path,
            ];
        });

        // Filesystem deletion cannot participate in the database transaction.
        // Wait until the guarded user delete commits so a rolled-back account
        // deletion never removes the profile photo from a surviving account.
        if ($profilePhoto !== null) {
            Storage::disk($profilePhoto['disk'])->delete($profilePhoto['path']);
        }
    }
}
