<?php

namespace App\Sync\Resources;

use Nextpointer\Bridge\AbstractResource;
use Illuminate\Database\Eloquent\Model;

/**
 * DemoResource - Ένα πλήρες παράδειγμα υλοποίησης BridgeResource.
 * Κάθε μέθοδος που βλέπετε εδώ μπορεί να παραλειφθεί αν θέλετε τις default τιμές.
 */
class DemoResource extends AbstractResource
{
    /**
     * Επιστρέφει το πλήρες namespace του Model στη Laravel.
     */
    public function getModel(): string
    {
        return \App\Models\User::class;
    }

    /**
     * Το πεδίο που ορίζει τη μοναδικότητα στη βάση δεδομένων (π.χ. email, sku, ean).
     * Το Bridge θα δημιουργήσει αυτόματα UNIQUE INDEX σε αυτό το πεδίο.
     */
    public function getUniqueKey(): string
    {
        return 'email';
    }

    /**
     * Το αναγνωριστικό της εξωτερικής πηγής (π.χ. το ID στο PrestaShop ή στο ERP).
     */
    public function getIdentifierField(): string
    {
        return 'remote_id';
    }

    /**
     * Ποια πεδία επιτρέπεται να γίνονται update κατά το συγχρονισμό.
     */
    public function getUpdateColumns(): array
    {
        return ['name', 'email', 'active', 'hash', 'updated_at', 'deleted_at'];
    }

    /**
     * Η καρδιά του Resource. Μετατρέπει το raw array του API σε μορφή πίνακα βάσης.
     */
    public function map(array $row): array
    {
        return [
            'remote_id' => $row['id'],
            'name'      => $row['firstname'] . ' ' . $row['lastname'],
            'email'     => $row['email'],
            'active'    => (bool) $row['active'],
        ];
    }

    /**
     * Validation πριν την εγγραφή. Αν επιστρέψει string (μήνυμα σφάλματος),
     * η εγγραφή μπαίνει στην "Καραντίνα" (sync_exceptions).
     */
    public function validate(array $mapped): ?string
    {
        if (empty($mapped['email'])) return 'missing_email';
        return null;
    }

    /**
     * Ποια πεδία συμμετέχουν στον υπολογισμό του hash.
     * Αν κανένα από αυτά δεν αλλάξει στο API, το Bridge δεν θα κάνει update ούτε θα log-άρει activity.
     */
    public function getHashFields(): array
    {
        return ['name', 'email', 'active'];
    }

    /**
     * Αν true, τα δεδομένα περνάνε πρώτα από staging πίνακα πριν την οριστικοποίηση.
     */
    public function useStaging(): bool { return true; }

    /**
     * Αν true, το Bridge θα ζητήσει από τον Provider μόνο τα records που άλλαξαν μετά το τελευταίο sync.
     */
    public function syncByDate(): bool { return true; }

    /**
     * Πόσα records να ζητάει ανά API call.
     */
    public function getBatchLimit(): int { return 1000; }

    /**
     * Αν true, θα καταγράφονται οι αλλαγές (Created/Updated/Deleted) στον πίνακα sync_activities.
     */
    public function shouldLog(): bool { return true; }

    /**
     * Αν true, κατά το Full Sync, όσα records υπάρχουν στη βάση αλλά ΛΕΙΠΟΥΝ από το API θα γίνουν Soft Delete.
     */
    public function shouldCleanup(): bool { return true; }

    /**
     * Hook που εκτελείται ακριβώς πριν την εγγραφή στη βάση (για τελικό formatting).
     */
    public function beforeSync(array &$mapped): void
    {
        $mapped['name'] = trim($mapped['name']);
    }

    /**
     * Hook που εκτελείται αφού η εγγραφή περάσει επιτυχώς στον Live πίνακα.
     */
    public function afterSync(array $originalRow, Model $modelInstance): void
    {
        // π.χ. dispatch(new SendWelcomeEmail($modelInstance));
    }
}
