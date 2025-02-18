<?php
/*
 * Copyright (c) 2021.
 * Desarrollado desde Goltratec S.L.
 * @author Francisco José García Alonso
 */

namespace FacturaScripts\Plugins\ImportadorStripe\Model;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\BusinessDocumentTools;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Model\ReciboCliente;
use FacturaScripts\Core\Model\Serie;
use FacturaScripts\Dinamic\Lib\Accounting\InvoiceToAccounting;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\LineaFacturaCliente;
use Stripe\Exception\ApiErrorException;
use Stripe\Product;

class InvoiceStripe
{
    public string $id;
    public string $numero;
    public string $date;
    public float $amount;
    public string $status;
    public string $customer_id;
    public ?string $customer_email;
    public ?string $fs_idFsCustomer = null;
    public ?string $fs_idFactura;
    public ?string $fs_customerName;
    public array $lines;


    public function __construct($data = [])
    {
    }

    static function loadSkStripe()
    {
        return SettingStripeModel::getSks();
    }

    /**
     * Devuelve las facturas de Stripe dentro del intervalo de fecha y a partir del id $start (en caso de recibirlo)
     * que han sido pagadas, tiene un importe > 0 y no tienen el metadato fs_idFactura
     * @param null $start id de la factura desde la que comenzar a cargar
     * @param int $limit número máximo de registros que carga
     * @param int $initDate por defecto es 1 de Enero de 1990
     * @param int|null $endDate por defecto es la fecha actual
     * @param int|string $sk_stripe_index indice del array de sk de la cuenta de stripe que queremos consultar
     * @return array
     *
     */
    static function loadInvoicesNotProcessed(int $sk_stripe_index, $start = null, int $limit = 5, int $initDate = 631200892, int $endDate = null)
    {
        try {
            //fuerzo este valor
            $limit = 100000;
            // Cargo los las secretKeys de las cuentas de script que hay dadas de alta en los settings de fs

            $stripe_ids = self::loadSkStripe();
            $data = []; //array donde vamos a volcar las facturas procesadas
            // Cargo el index del sk pasado a la función
            $sk_stripe = $stripe_ids[$sk_stripe_index];
            if ($sk_stripe === '') {
                return ['status' => false, 'message' => 'No ha indicado el sk de stripe que desea consultar'];
            }
            $stripe_id = $sk_stripe['sk'];

            // Parametros para hacer filtro
            if ($endDate === null) { // seteo valor por defecto en caso que venga como null
                $endDate = time();
            }
            $params = ['status' => 'paid', 'limit' => $limit, 'created' => ['lte' => $endDate, 'gte' => $initDate]];
           

            $stripe = new \Stripe\StripeClient($stripe_id);
            $stripe_response = $stripe->invoices->all($params);
            $_data = [];
            array_filter($stripe_response->data, function ($inv) use (&$_data) {
                if ($inv->amount_paid > 0 && (!isset($inv->metadata['fs_idFactura']) || $inv->metadata['fs_idFactura'] == '')) {
                    $_data[] = $inv;
                }
            });
            $data = self::processInvoicesObject($_data, $sk_stripe_index);


            $response = [
                'status' => true,
                'data' => $data,
                'last' => $stripe_response->data[count($stripe_response->data) - 1]->id,
                'limit' => $limit,
                'has_more' => $stripe_response->has_more
            ];

            return $response;
        } catch (ApiErrorException $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    static function loadInvoiceFromStripe(string $id, int $sk_stripe_index)
    {
        $stripe_ids = self::loadSkStripe();
        $sk_stripe = $stripe_ids[$sk_stripe_index];
        if ($sk_stripe === '') {
            return ['status' => false, 'message' => 'No ha indicado el sk de stripe que desea consultar'];
        }
        $stripe_id = $sk_stripe['sk'];
        try {
            $stripe = new \Stripe\StripeClient($stripe_id);
            $invoices[] = $stripe->invoices->retrieve($id); //guardamos en un array porque el método que genera el objeto lo tenemos definido así
            $res = self::processInvoicesObject($invoices, $sk_stripe_index);
            return ['status' => true, 'data' => $res];
        } catch (\Exception $ex) {
            return ['status' => false, 'message' => 'Error al obtener la factura desde stripe ' . $ex->getMessage()];
        }
    }

    static public function setFsIdCustomer(string $stripe_customer_id, int $sk_stripe_index, string $fs_idFsCustomer)
    {
        $stripe_ids = self::loadSkStripe();
        $sk_stripe = $stripe_ids[$sk_stripe_index];
        if ($sk_stripe === '') {
            return ['status' => false, 'message' => 'No ha indicado el sk de stripe que desea consultar'];
        }
        $stripe_id = $sk_stripe['sk'];
        try {
            $stripe = new \Stripe\StripeClient($stripe_id);
            $customer = $stripe->customers->update($stripe_customer_id, [
                'metadata' => ['fs_idFsCustomer' => $fs_idFsCustomer]
            ]);
            return ['status' => true, 'data' => $customer];
        } catch (\Exception $ex) {
            return ['status' => false, 'message' => 'Error al obtener el cliente desde stripe ' . $ex->getMessage()];
        }
    }

    /**
     * Función que recibe un array de facturas de stripe y lo parsea para convertrlo en array de objetos de tipo
     * InvoiceStripe. Devuelve un array de InvoiceStripe
     * @param array $data
     * @return array
     */
    static private function processInvoicesObject(array $data, $sk_stripe_index, $withLines = true): array
    {
        $res = [];
        $errors = [];

        foreach ($data as $inv) {
            //obtengo el cliente de stripe.
            $customer = self::getStripeClient($inv->customer, $sk_stripe_index);
            if ($customer === null) {
                throw new \Exception('No se ha podido cargar el cliente de stripe correspondiente a la factura ' . $inv->id);
            }

            if ($inv->amount_paid > 0 && (!isset($inv->metadata['fs_idFactura']) || $inv->metadata['fs_idFactura'] == '')) {
                $invoice = new InvoiceStripe();
                $invoice->id = $inv->id;
                $invoice->numero = $inv->number;
                $invoice->status = $inv->status;
                $invoice->customer_id = $inv->customer;
                $invoice->customer_email = $inv->customer_email;
                $invoice->fs_idFactura = isset($inv->metadata['fs_idFactura']) ? $inv->metadata['fs_idFactura'] : null;
                $_fs_idCustomer = isset($customer->metadata['fs_idFsCustomer']) ? $customer->metadata['fs_idFsCustomer'] : null;
                $fs_customer = new \FacturaScripts\Core\Model\Cliente();
                $fs_customer->loadFromCode($_fs_idCustomer);

                if ($_fs_idCustomer !== null && $fs_customer->exists()) {
                    $invoice->fs_idFsCustomer = $_fs_idCustomer;
                    $invoice->fs_customerName = $fs_customer->nombre;
                }
                $invoice->date = Helper::castTime($inv->created);
                $invoice->amount = $inv->amount_paid / 100;

                if (isset($inv->lines) && $withLines) {
                    foreach ($inv->lines->data as $l) {
                        $period_start = (isset($l->period->start)) ? $l->period->start : null;
                        $period_end = (isset($l->period->end)) ? $l->period->end : null;
                        $fs_product_id = '';
                        if ($l->price !== null && $l->price->product !== null && $l->price->product !== '') {
                            $fs_product_id = ProductModel::getFsProductIdFromStripe($sk_stripe_index, $l->price->product);
                            // Compruebo si hay correlación entre producto de stripe y fs
                            if ($fs_product_id === '') {
                                $errors[] = ['message' => 'El producto de stripe no tiene correlación con el de FS', 'data' => $l->price->product . '-' . $l->description];
                            } else {
                                // Comprueba si el fs_product_id existe en fs
                                $product = new Producto();
                                if (!$product->loadFromCode($fs_product_id))
                                    $errors[] = ['message' => 'El producto FS relacionado con el producto de stripe no existe', 'data' => $fs_product_id];
                                else
                                    $tax = $product->getTax();
                            }
                        } else {
                            $errors[] = ['message' => 'No se ha podido cargar el producto desde stripe', 'data' => $l];
                        }
                        $invoice->lines[] = ['codimpuesto' => $tax->codimpuesto, 'iva' => $tax->iva, 'recargo' => $tax->recargo, 'unit_amount' => $l->price->unit_amount / 100, 'quantity' => $l->quantity, 'fs_product_id' => $fs_product_id, 'amount' => $l->amount / 100, 'description' => $l->plan->name . ' ' . $l->description, 'period_start' => $period_start, 'period_end' => $period_end];
                    }

                }
                if (count($errors) == 0)
                    $res[] = $invoice;
                else
                    throw new Exception(serialize($errors));
            }
        }

        return $res;
    }

    /**
     * Devuelve el cliente de stripe que corresponde con el $customer_id recibido
     * @param $customer_id
     * @return mixed || null
     */
    static private function getStripeClient($customer_id, $sk_stripe_index)
    {
        $stripe_ids = self::loadSkStripe();
        $sk_stripe = $stripe_ids[$sk_stripe_index];
        if ($sk_stripe === '') {
            return ['status' => false, 'message' => 'No ha indicado el sk de stripe que desea consultar'];
        }
        $stripe_id = $sk_stripe['sk'];
        try {
            $stripe = new \Stripe\StripeClient($stripe_id);
            return $stripe->customers->retrieve($customer_id);
        } catch (\Exception $ex) {
            return null;
        }
    }

    /**
     * Funcion que crea una nueva factura en FS.
     * Crea la factura y deuvelve un array con las propiedades bool status y integer code
     * return Array
     */
    static public function generateFSInvoice($id_invoice_stripe, $sk_stripe_index, $mark_as_paid = false, $payment_method = null, $send_by_email = false)
    {
        $invoices = self::loadInvoiceFromStripe($id_invoice_stripe, $sk_stripe_index);
        $invoice = $invoices['data'][0];
        $result = false;

        // COMPROBAMOS QUE LA FACTURA DE ESTRIPE SE HA CARGADO CORRECTAMENTE
        if ($invoice === null) {
            throw new Exception('No se ha podido cargar la factura de stripe');
        }
        // COMPROBAMOS QUE LA FACTURA DE STRIPE TIENE UN CLIENTE DE FS ASOCIADO
        if (!isset($invoice->fs_idFsCustomer) || $invoice->fs_idFsCustomer === '') {
            throw new Exception('La factura de stripe no tiene asociado un cliente de FS');
        }
        // COMPROBAMOS QUE LA FACTURA DE STRIPE NO ESTE VINCULADA YA A UNA FACTURA DE FS
        if (isset($invoice->fs_idFactura) && ($invoice->fs_idFactura === null || $invoice->fs_idFactura !== '')) {
            throw new Exception('La factura de stripe ya está vinculada a la factura de FS ' . $invoice->fs_idFactura);
        }
        // COMPROBAMOS QUE EL CLIENTE ASOCIADO EN FS EXISTE.
        $client = new Cliente();
        $res_load_client = $client->loadFromCode($invoice->fs_idFsCustomer);
        if (!$res_load_client) {
            throw new Exception('Hubo un problema al cargar el cliente de FS relacionado con la factura de Stripe. Es posible que no exista ese cliente en FS');
        }

        $default_serie = new Serie();
        $default_serie->loadFromCode($client->codserie);


        // SI HA PASADO LAS COMPROBACIONES ENTONCES CREAMOS LA FACTURA DE FS.
        // INICIO UNA TRASACCIÓN
        $database = new DataBase();
        $database->beginTransaction();

        $invoiceFs = new FacturaCliente();
        $invoiceFs->setSubject($client);

        // Si se crea la factura, entonces creo las lineas.
        if ($invoiceFs->save()) {
            foreach ($invoice->lines as $l) {
                /** \FacturaScripts\Core\Model\LineaFacturaCliente $line */
                $line = $invoiceFs->getNewLine();
                $line->idfactura = $invoiceFs->idfactura;
                $line->descripcion = $l['description'];
                if ($l['period_start']) {
                    $line->descripcion = $line->descripcion . ' desde ' . date('d-m-Y', $l['period_start']);
                }
                if ($l['period_end']) {
                    $line->descripcion = $line->descripcion . ' hasta ' . date('d-m-Y', $l['period_end']);
                }
                $productCode = '';
                if ($l['fs_product_id'] !== null && $l['fs_product_id'] !== '') {
                    $producto = new Producto();
                    $producto->loadFromCode($l['fs_product_id']);
                    $productCode = $producto->referencia;
                    $line->idproducto = $l['fs_product_id'];
                    $line->referencia = $productCode;
                }

                $line->cantidad = $l['quantity'];
                $line->pvpunitario = $l['unit_amount'] / (1 + ($l['iva'] / 100));
                $line->pvptotal = $l['amount'] / (1 + ($l['iva'] / 100));
                if ($client->regimeniva !== 'Exento') {
                    $line->codimpuesto = $l['codiimpuesto'];
                    $line->iva = $l['iva'];
                }

                if (!$line->save()) {
                    $database->rollback();
                    throw new Exception('Ha ocurrido algun error mientras se creaban la lineas de la factura.');
                }
            }
        } else {
            $database->rollback();
            throw new Exception('Ha ocurrido algun error mientras se creaba la factura.');
        }

        // recalculo los totales
        $tool = new BusinessDocumentTools();
        $tool->recalculate($invoiceFs);

        // asigno al numero2 el numero de factura de stripe
        $invoiceFs->numero2 = $invoice->numero;
        // si hay que marcarla como pagada

        if ($mark_as_paid === true && $payment_method !== null) $invoiceFs->codpago = $payment_method;
        $invoiceFs->save();
        //Genero el asiento contable
        if (!self::generateAccounting($invoiceFs)) {
            $database->rollback();
            throw new Exception('No se ha podido generar la factura porque hubo un error al generar el asiento contable');
        }

        if ($mark_as_paid === true && $payment_method !== null) {
            foreach ($invoiceFs->getReceipts() as $receipt) {
                $receipt->pagado = true;
                if (!$receipt->save()) {
                    $database->rollback();
                    throw new Exception('No se ha podido generar la factura porque hubo un error al darla por pagada');
                }
            }
        }
        // Inserto metadato en Stripe
        try {
            self::setFsIdToStripeInvoice($id_invoice_stripe, $invoiceFs->idfactura, $sk_stripe_index);
        } catch (Exception $ex) {
            $database->rollback();
            throw new Exception('No se ha podido crear la factura porque ha fallado al actualizar el documento de stripe');
        }
        // Si todo ha ido bien hago un commit
        $result = $database->commit();

        return ['status' => $result, 'code' => $invoiceFs->idfactura ?? null];
    }

    static private function generateAccounting($invoice)
    {
        $generator = new InvoiceToAccounting();
        $generator->generate($invoice);
        if (empty($invoice->idasiento) || !$invoice->save()) {
            return false;
        }
        return true;
    }

    static private function setFsIdToStripeInvoice($id_invoice_stripe, $fs_idFactura, $sk_stripe_index)
    {
        $stripe_ids = self::loadSkStripe();
        $sk_stripe = $stripe_ids[$sk_stripe_index];
        if ($sk_stripe === '') {
            return ['status' => false, 'message' => 'No ha indicado el sk de stripe que desea consultar'];
        }
        $stripe_id = $sk_stripe['sk'];
        try {
            $stripe = new \Stripe\StripeClient($stripe_id);
            $invoice = $stripe->invoices->update(
                $id_invoice_stripe,
                ['metadata' => ['fs_idFactura' => $fs_idFactura]]);
        } catch (Exception $ex) {
            throw new Exception('Error al vincular la factura de FS a la de Stripe ' . $ex->getMessage());
        }


    }
}
