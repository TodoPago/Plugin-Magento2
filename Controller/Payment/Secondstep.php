<?php
/**
 * Copyright � 2015 Inchoo d.o.o.
 * created by Zoran Salamun(zoran.salamun@inchoo.net)
 */
namespace Prisma\TodoPago\Controller\Payment;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order\Payment\Transaction;

class Secondstep extends \Magento\Framework\App\Action\Action
{
	
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;
    /**
     * @var PaymentHelper
     */
    protected $_paymentHelper;
	
    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    protected $transactionRepository;
    /**
     * @var Transaction\BuilderInterface
     */
    protected $transactionBuilder;

	/**
	 * @var OrderSender
	 */
	protected $_orderSender;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Psr\Log\LoggerInterface $logger,
        PaymentHelper $paymentHelper,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
		\Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
        $this->_checkoutSession = $checkoutSession;
        $this->_logger = $logger;
        $this->_paymentHelper = $paymentHelper;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder = $transactionBuilder;
		$this->_orderSender = $orderSender;
    }
	
    /**
     * Return checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckoutSession()
    {
        return $this->_checkoutSession;
    }
	
	public function execute()
    {
		$this->_logger->debug("TODOPAGO - SECOND STEP INIT");
        try {
			$id = $this->getRequest()->getParam("id");			
			$ak = $this->getRequest()->getParam("Answer");
			
			$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
			$order_model = $objectManager->get('Magento\Sales\Model\Order');
			$order = $order_model->load($id);

            $method = $order->getPayment()->getMethod();
            $methodInstance = $this->_paymentHelper->getMethodInstance($method);
			$this->_logger->debug("TODOPAGO - SECOND STEP SDK");
			$res = $methodInstance->secondStep($order, $ak);

			if($methodInstance->getAmbiente() == "test") {                    
				$message = "Todo Pago (TEST): " . $res['StatusMessage'];                    
			} else {                  
				$message = "Todo Pago: " . $res['StatusMessage'];                  
			}


            /*costo financiero*/
            $amountBuyer = isset($res['Payload']['Request']['AMOUNTBUYER'])?$res['Payload']['Request']['AMOUNTBUYER']:number_format($order->getGrandTotal(), 2, ".", "");
            $cf = $amountBuyer - $order->getGrandTotal();

            $order->setTodopagocostofinanciero($cf);
            $order->setGrandTotal($amountBuyer);
            $order->setBaseGrandTotal($amountBuyer);

            $order->save();

            $this->_logger->debug("TODOPAGO - CF: ".$order->getTodopagocostofinanciero());

			$payment = $order->getPayment();
			$payment->setIsTransactionClosed(1);
			
			$orderTransactionId = $payment->getTransactionId();
			$payment->setParentTransactionId($order->getId());
			$payment->setIsTransactionPending(false);
			$payment->setIsTransactionApproved(true);
		
			
			$transaction = $this->transactionBuilder->setPayment($payment)
				->setOrder($order)
				->setTransactionId($payment->getTransactionId())
				->build(Transaction::TYPE_CAPTURE);
			$payment->addTransactionCommentsToOrder($transaction, $message);

			$statuses = $methodInstance->getOrderStatuses();
			$status = $statuses["aprobado"];
			$state = \Magento\Sales\Model\Order::STATE_PROCESSING;
			$order->setState($state)->setStatus($status);
			$payment->setSkipOrderProcessing(true);
			
			$invoice = $objectManager->create('Magento\Sales\Model\Service\InvoiceService')->prepareInvoice($order);
			$invoice = $invoice->setTransactionId($payment->getTransactionId())
                ->addComment("Invoice created.")
				->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);

			$invoice->setGrandTotal($amountBuyer);
            $invoice->setBaseGrandTotal($amountBuyer);

            $invoice->register()
                ->pay();
			$invoice->save();
					
			// Save the invoice to the order
			$transaction = $this->_objectManager->create('Magento\Framework\DB\Transaction')
				->addObject($invoice)
				->addObject($invoice->getOrder());

			$transaction->save();
			
			$order->addStatusHistoryComment(
				__('Invoice #%1.', $invoice->getId())
			)
			->setIsCustomerNotified(true);
			
			$order->save();

			$this->_sendNewOrderMail($order);
			
			$this->_redirect('checkout/onepage/success');			
        } catch (\Exception $e) {
			$this->_logger->debug("TODOPAGO - SECOND STEP EXCEPTION");
			$id = $this->getRequest()->getParam("id");			
			$ak = $this->getRequest()->getParam("Answer");
			
			$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
			$order_model = $objectManager->get('Magento\Sales\Model\Order');
			$order = $order_model->load($id);

            $method = $order->getPayment()->getMethod();
            $methodInstance = $this->_paymentHelper->getMethodInstance($method);
			
			$payment = $order->getPayment();
			$payment->setIsTransactionClosed(1);
			
			if($methodInstance->getAmbiente() == "test") {                    
				$message = "Todo Pago (TEST): " . $e->getMessage();                    
			} else {                  
				$message = "Todo Pago: " . $e->getMessage();                  
			}
			
			$transaction = $this->transactionRepository->getByTransactionType(
				Transaction::TYPE_ORDER,
				$payment->getId(),
				$payment->getOrder()->getId()
			);
			
			if($transaction == null) {
				$orderTransactionId = $order->getId();
				$transaction = $this->transactionBuilder->setPayment($payment)
					->setOrder($order)
					->setTransactionId($order->getId())
					->build(Transaction::TYPE_CAPTURE);
			}
			
			$payment->addTransactionCommentsToOrder($transaction, $message);

			$statuses = $methodInstance->getOrderStatuses();
			$status = $statuses["rechazado"];
			$state = \Magento\Sales\Model\Order::STATE_CANCELED;
			$order->setState($state)->setStatus($status);
			$payment->setSkipOrderProcessing(true);
			$payment->setIsTransactionDenied(true);
			$transaction->close();
			$order->cancel()->save();
			
            $this->messageManager->addException($e, __('Something went wrong, please try again later'));
            $this->_logger->critical($e);
            $this->_getCheckoutSession()->restoreQuote();
            $this->_redirect('checkout/onepage/failure');
        }
    }

	/**
	 * Send new order Mail
	 */
	protected function _sendNewOrderMail(\Magento\Sales\Model\Order $order)
	{
		if ($order->getCanSendNewEmailFlag() && !$order->getEmailSent()) {
			try {
				$this->_orderSender->send($order);
			} catch (\Exception $e) {
				$this->_logger->critical($e);
			}
		}
	}
}