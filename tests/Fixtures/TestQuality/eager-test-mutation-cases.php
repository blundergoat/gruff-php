<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;

final class EagerPositiveMutationCasesTest extends TestCase
{
    public function testProcessesOrderAndSendsReceipt(): void
    {
        $service = new OrderServiceFixture();
        $order = $service->processOrder();
        $receipt = $service->sendReceipt();
        $audited = $service->auditTrailWritten();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
        self::assertTrue($audited);
    }

    public function testStaticServiceHandlesTwoBehaviors(): void
    {
        $order = OrderServiceFixture::processOrder();
        $receipt = OrderServiceFixture::sendReceipt();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
        self::assertTrue(true);
    }

    public function testNewServiceHandlesTwoBehaviors(): void
    {
        $order = (new OrderServiceFixture())->processOrder();
        $receipt = (new OrderServiceFixture())->sendReceipt();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
        self::assertTrue(true);
    }

    public function testPropertyServiceHandlesTwoBehaviors(): void
    {
        $order = $this->service->processOrder();
        $receipt = $this->service->sendReceipt();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
        self::assertTrue(true);
    }

    public function testObservationNamedMethodsStillCountOnDomainReceiver(): void
    {
        $status = $service->getStatus();
        $receipt = $service->hasReceipt();

        self::assertSame('paid', $status);
        self::assertTrue($receipt);
        self::assertTrue(true);
    }

    public function testSkipsNoiseBeforeRealSutCalls(): void
    {
        $this->prepareFixture();
        helper_function();
        $service = new OrderServiceFixture();
        $order = $service->processOrder();
        $receipt = $service->sendReceipt();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
        self::assertTrue(true);
    }

    public function testSkipsResultObservationBeforeRealSutCalls(): void
    {
        $result = ResultFactory::make();
        $result->status();
        $service = new OrderServiceFixture();
        $order = $service->processOrder();
        $receipt = $service->sendReceipt();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
        self::assertTrue(true);
    }

    public function testDomainStartWaitStopMethodsAreSutCalls(): void
    {
        $service->start();
        $service->wait();
        $service->stop();

        self::assertTrue(true);
        self::assertSame('done', $service->status());
        self::assertNotNull($service);
    }

    public function testReceiverCaseIsNormalisedForVariables(): void
    {
        $service->processOrder();
        $Service->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', $service->status());
        self::assertSame('sent', $Service->status());
    }

    public function testReceiverCaseIsNormalisedForStaticCalls(): void
    {
        OrderServiceFixture::processOrder();
        ORDERSERVICEFIXTURE::sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', OrderServiceFixture::status());
        self::assertSame('sent', ORDERSERVICEFIXTURE::status());
    }

    public function testReceiverCaseIsNormalisedForNewExpressions(): void
    {
        (new OrderServiceFixture())->processOrder();
        (new ORDERSERVICEFIXTURE())->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', 'paid');
        self::assertSame('sent', 'sent');
    }

    public function testReceiverCaseIsNormalisedForProperties(): void
    {
        $this->service->processOrder();
        $this->Service->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', $this->service->status());
        self::assertSame('sent', $this->Service->status());
    }

    public function testKnownReceiverNonObservationMethodsStillCount(): void
    {
        $html->render();
        $html->compile();

        self::assertTrue(true);
        self::assertSame('report', $html->summary());
        self::assertNotNull($html);
    }

    public function testLargestReceiverTieKeepsFirstReceiver(): void
    {
        $first->processOrder();
        $first->sendReceipt();
        $second->shipOrder();
        $second->sendNotification();

        self::assertTrue(true);
        self::assertSame('paid', $first->status());
        self::assertSame('sent', $second->status());
    }
}

final class EagerNegativeMutationCasesTest extends TestCase
{
    public function testTwoAssertionMultiActStaysBelowDefaultThreshold(): void
    {
        $service = new OrderServiceFixture();
        $order = $service->processOrder();
        $receipt = $service->sendReceipt();

        self::assertSame('paid', $order);
        self::assertSame('sent', $receipt);
    }

    public function testAssertionHelperCallsDoNotBecomeSutCalls(): void
    {
        $service = new OrderServiceFixture();
        $order = $service->processOrder();

        $this->assertSame('paid', $order);
        $this->assertTrue(true);
        $this->assertNotNull($service);
    }

    public function testDirectThisHelpersDoNotBecomeSutCalls(): void
    {
        $this->prepareFixture();
        $this->resetFixture();
        $this->bootFixture();

        self::assertTrue(true);
        self::assertSame('ready', 'ready');
        self::assertNotNull($this);
    }

    public function testAssertionNamedCollaboratorCallsDoNotBecomeSutCalls(): void
    {
        $verifier->assertValid();
        $verifier->assertComplete();
        $verifier->assertSaved();

        self::assertTrue(true);
        self::assertSame('ready', 'ready');
        self::assertNotNull($verifier);
    }

    public function testNestedSutCallsInsideAssertionsAreObservations(): void
    {
        $service = new OrderServiceFixture();

        self::assertSame('paid', $service->processOrder());
        self::assertSame('sent', $service->sendReceipt());
        self::assertTrue($service->auditTrailWritten());
    }

    public function testMockExpectationCallsDoNotBecomeSutCalls(): void
    {
        $mock->expects($this->once())->method('send')->with('receipt');
        $service = new OrderServiceFixture();
        $order = $service->processOrder();

        self::assertSame('paid', $order);
        self::assertTrue(true);
        self::assertNotNull($mock);
    }

    public function testGenericSetTimeoutIsSetupNotSutExercise(): void
    {
        $timer->setTimeout(120);
        $result = $timer->run();

        self::assertSame('done', $result);
        self::assertTrue(true);
        self::assertNotNull($timer);
    }

    public function testProcessHarnessCallsDoNotBecomeSutExercise(): void
    {
        $process->start();
        $process->wait();
        $process->stop();

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('ok', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    public function testUppercaseProcessHarnessReceiverIsStillRecognised(): void
    {
        $Process->start();
        $Process->wait();
        $Process->stop();

        self::assertSame(0, $Process->getExitCode());
        self::assertStringContainsString('ok', $Process->getOutput());
        self::assertSame('', $Process->getErrorOutput());
    }

    public function testResultVariableObservationsDoNotBecomeSutExercise(): void
    {
        $result = ResultFactory::make();
        $status = $result->status();
        $payload = $result->toArray();

        self::assertSame('paid', $status);
        self::assertIsArray($payload);
        self::assertTrue(true);
    }

    public function testKnownObservationReceiversDoNotBecomeSutExercise(): void
    {
        $title = $html->getTitle();
        $hasErrors = $html->hasErrors();
        $count = $html->count();

        self::assertSame('Report', $title);
        self::assertFalse($hasErrors);
        self::assertSame(0, $count);
    }

    public function testCountObservationDoesNotPairWithNonObservationOnKnownReceiver(): void
    {
        $count = $html->count();
        $summary = $html->render();

        self::assertSame(0, $count);
        self::assertSame('report', $summary);
        self::assertNotNull($html);
    }

    public function testDistinctStaticReceiversDoNotCollapseTogether(): void
    {
        OrderServiceFixture::processOrder();
        ReceiptServiceFixture::sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', OrderServiceFixture::status());
        self::assertSame('sent', ReceiptServiceFixture::status());
    }

    public function testDistinctVariableReceiversDoNotCollapseTogether(): void
    {
        $service->processOrder();
        $receipt->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', $service->status());
        self::assertSame('sent', $receipt->status());
    }

    public function testDistinctNewReceiversDoNotCollapseTogether(): void
    {
        (new OrderServiceFixture())->processOrder();
        (new ReceiptServiceFixture())->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', 'paid');
        self::assertSame('sent', 'sent');
    }

    public function testDistinctPropertyNamesDoNotCollapseTogether(): void
    {
        $this->orderService->processOrder();
        $this->receiptService->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', $this->orderService->status());
        self::assertSame('sent', $this->receiptService->status());
    }

    public function testDistinctPropertyOwnersDoNotCollapseTogether(): void
    {
        $this->service->processOrder();
        $other->service->sendReceipt();

        self::assertTrue(true);
        self::assertSame('paid', $this->service->status());
        self::assertSame('sent', $other->service->status());
    }

    public function testSecondResultVariableObservationsRemainSkipped(): void
    {
        $first = ResultFactory::make();
        $second = ResultFactory::make();
        $status = $second->status();
        $payload = $second->toArray();

        self::assertNotNull($first);
        self::assertSame('paid', $status);
        self::assertIsArray($payload);
    }

    public function testResultVariablesAfterNonVariableAssignmentRemainSkipped(): void
    {
        $this->result = ResultFactory::make();
        $result = ResultFactory::make();
        $status = $result->status();
        $payload = $result->toArray();

        self::assertNotNull($this->result);
        self::assertSame('paid', $status);
        self::assertIsArray($payload);
    }

    public function testChainedResultVariableObservationsRemainSkipped(): void
    {
        $result = ResultFactory::make();
        $count = $result->items()->count();
        $first = $result->items()->first();

        self::assertSame(1, $count);
        self::assertSame('item', $first);
        self::assertNotNull($result);
    }
}
