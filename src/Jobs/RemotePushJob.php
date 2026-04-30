<?php
namespace Nextpointer\Bridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Nextpointer\Prestashop\Facades\Prestashop;

class RemotePushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param Model $model Το Laravel Model (π.χ. Product)
     * @param array $params Τα δεδομένα που θα σταλούν στο API
     * @param string $target Πού πάει η πληροφορία; ('toShop' ή 'toERP')
     * @param string|int|null $remoteId Το ID στο σύστημα προορισμού (για το update)
     */
    public function __construct(
        protected Model $model,
        protected array $params,
        protected string $target,
        protected $remoteId = null
    ) {}

    public function handle()
    {
        try {
            if ($this->target === 'toShop') {
                $this->pushToPrestashop();
            } elseif ($this->target === 'toERP') {
                $this->pushToSoftone();
            }
        } catch (\Exception $e) {
            Log::error("Remote Push Error [{$this->target}]: " . $e->getMessage());
        }
    }

    /**
     * Ενημέρωση του PrestaShop χρησιμοποιώντας το πακέτο Nextpointer\Prestashop
     */
    protected function pushToPrestashop()
    {
        // 1. Εκτέλεση του αιτήματος στο API
        $response = \Nextpointer\Prestashop\Facades\Prestashop::products()
            ->updateOrCreate($this->params, $this->remoteId);



        // 2. Έλεγχος αν η απόκριση είναι επιτυχής (βάσει του raw -> success που βλέπω στο dd)
        $isSuccess = $response['raw']['success'] ?? false;


        if ($isSuccess) {
            // Αν δεν είχαμε remoteId (ήταν Create), αποθηκεύουμε το νέο ID από το ['data']['id']
            if (!$this->remoteId && isset($response['data']['id'])) {
                $this->model->prestashop_id = $response['data']['id'];
                $this->model->saveQuietly();
                \Log::info("Product linked to PrestaShop: ID " . $response['data']['id']);
            }
        } else {
            \Log::error("PrestaShop Push Failed for Model ID: " . $this->model->id, $response);
        }
    }

    /**
     * Ενημέρωση του Softone
     */
    protected function pushToSoftone()
    {
        // Εδώ καλείς το δικό σου Softone Service/Client
        // Παράδειγμα:
        // $softone = app(\Nextpointer\Softone\Client::class);
        // $response = $softone->updateOrCreate('MTRL', $this->params, $this->remoteId);

        Log::info("Pushing to ERP: Logic for Softone goes here.");
    }
}
