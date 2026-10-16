@extends('layouts.app')
@section('title', 'How it works')
@section('subtitle', 'The front desk in five minutes: one person, one screen, from the first hello to the receipt.')
@section('breadcrumbs')<li class="breadcrumb-item active">How it works</li>@endsection

@section('content')
<div class="row g-4">
    <div class="col-lg-8">

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-lightbulb text-warning me-2"></i>The idea</div>
            <div class="card-body">
                <p class="mb-2"><strong>The patient's page is your desk.</strong> You never have to hunt through Bills, Receipts and Visits separately.
                Open the patient, and everything you need is right there: what happened before, what they owe, and the buttons to do the next thing.</p>
                <div class="row g-2 text-center mt-2">
                    <div class="col-6 col-md-3"><div class="chip chip-brand w-100 justify-content-center py-2"><i class="bi bi-search"></i>1 · Find</div></div>
                    <div class="col-6 col-md-3"><div class="chip chip-brand w-100 justify-content-center py-2"><i class="bi bi-receipt"></i>2 · New bill</div></div>
                    <div class="col-6 col-md-3"><div class="chip chip-brand w-100 justify-content-center py-2"><i class="bi bi-cash-coin"></i>3 · Take payment</div></div>
                    <div class="col-6 col-md-3"><div class="chip chip-brand w-100 justify-content-center py-2"><i class="bi bi-printer"></i>4 · Print receipt</div></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><span class="badge bg-success me-2">First time</span>A new patient walks in</div>
            <div class="card-body py-2">
                <div class="help-step"><span class="n">1</span><div><strong>Search first.</strong> Type their name or phone in the box at the top (press <kbd>/</kbd> to jump there). Always check: they may already be registered.</div></div>
                <div class="help-step"><span class="n">2</span><div><strong>Not found? Register.</strong> The search offers <em>“Register … as a new patient”</em>. Fill the name, gender and mobile number (age or date of birth helps). The system gives them an ID like <code>PAT-2026-00013</code>.</div></div>
                <div class="help-step"><span class="n">3</span><div><strong>Click “Save &amp; start bill”.</strong> Their page opens with the bill panel already open.</div></div>
                <div class="help-step"><span class="n">4</span><div><strong>Make the bill.</strong> Pick the doctor, add the services (price fills in; you can change it), add a discount if allowed. Leave <em>“Also record today's visit”</em> on.</div></div>
                <div class="help-step"><span class="n">5</span><div><strong>Take the money in the same panel.</strong> Type what they are paying now (or press <em>Pay full</em>), choose Cash / UPI / Card…, add the reference number for non-cash. <em>Save bill.</em></div></div>
                <div class="help-step"><span class="n">6</span><div><strong>Print.</strong> A banner on the patient page gives <em>Print bill</em> and <em>Print receipt</em> buttons.</div></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><span class="badge bg-info me-2">Coming back</span>The same patient comes a second time</div>
            <div class="card-body py-2">
                <div class="help-step"><span class="n">1</span><div><strong>Search</strong> by name or phone and open them. You'll see at once: <em>how much they owe</em> (red box), <em>what's already happened today</em>, and their full history, newest first.</div></div>
                <div class="help-step"><span class="n">2</span><div><strong>Did they owe money from last time?</strong> Click <em>Receive payment</em>. Type what they hand over. It is applied to their <em>oldest unpaid bill first</em>, then the next, and you print one receipt for the whole amount.</div></div>
                <div class="help-step"><span class="n">3</span><div><strong>New treatment today?</strong> Click <em>New bill</em>, exactly as for a new patient. It is a separate bill with its own number; the old bills stay as they were.</div></div>
                <div class="help-step"><span class="n">4</span><div><strong>Doctor wants them back?</strong> <em>Set follow-up</em> on the same page: date, optional time, reason.</div></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-wallet2 me-2 text-primary"></i>How money works (so it never feels confusing)</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Bill</dt><dd class="col-sm-8">What was done and what it costs. Printed as an <em>Invoice</em> (<code>INV-…</code>). Never deleted: if it was a mistake, an admin <em>cancels</em> it with a reason.</dd>
                    <dt class="col-sm-4">Paid / Balance</dt><dd class="col-sm-8">A bill is <strong>Unpaid</strong>, <strong>Partially paid</strong> or <strong>Paid</strong>. It updates by itself when you take payment. The red <em>Due</em> box on the patient page is the total balance across all their bills.</dd>
                    <dt class="col-sm-4">Receipt</dt><dd class="col-sm-8">Proof of every payment (<code>RCP-…</code>). Each payment gets one; a <em>Receive payment</em> that covers several bills prints as one combined receipt.</dd>
                    <dt class="col-sm-4">Pay later / part payment</dt><dd class="col-sm-8">Save the bill with 0 or a smaller amount. The rest stays as <em>Due</em> on the patient's page until it is received.</dd>
                    <dt class="col-sm-4">Wrong amount?</dt><dd class="col-sm-8">You can't edit a saved bill, which protects the books. Ask an admin to cancel it (reason required), then make a correct one.</dd>
                </dl>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-list-check me-2 text-primary"></i>Doctor's fee, changing prices, new services</div>
            <div class="card-body">
                <p><strong>Doctor's fee.</strong> When you choose the doctor on a bill, their consultation fee is added as the first line. Change the price, or press the red ✕ to remove it (for example a free follow-up).</p>
                <p><strong>Prices that are not fixed.</strong> Under each price you'll see the <em>standard price</em>. If you charge less, it shows <em>↓ ₹300 less</em> and the total shows <em>Below standard prices</em>, so you always know how much was reduced. A <em>discount</em> is different: it is for the whole bill and needs a reason.</p>
                <p class="mb-0"><strong>A treatment that is not in the list.</strong> Type its name in the service box and choose <em>Add “…” as a new service</em>. Enter the category and the usual price, and it is added and used on the bill immediately.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-calendar2-check me-2 text-primary"></i>Treatment paid in instalments (EMI)</div>
            <div class="card-body">
                <p>For big treatments, like braces for ₹30,000 at ₹3,000 a month: on the patient's page choose <strong>More → New EMI plan</strong>. Enter the total and either the months or the monthly amount; the schedule is shown before you save.</p>
                <p><strong>Bill or no bill?</strong> A plan can stand alone, or you can pick one of the patient's <em>unpaid bills</em> on the EMI form to split it into instalments. Then payments on the plan also settle that bill, so nothing is counted twice, and the bill is paid through the plan from then on.</p>
                <p class="mb-0">The patient can then pay <em>any amount, any day</em>. It goes to the oldest unpaid instalment first and carries over. Their EMI shows in the <em>Due</em> box, and <em>Receive payment</em> has a tab for each plan, so bills and instalments are taken from the same dialog. Overdue instalments appear on the dashboard with a WhatsApp reminder button.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-sunset me-2 text-primary"></i>End of the day</div>
            <div class="card-body py-2">
                <div class="help-step"><span class="n">1</span><div><strong>Attendance</strong>: <em>Take attendance</em> (under “+ New”): press <em>Mark all present</em>, change the exceptions, save.</div></div>
                <div class="help-step"><span class="n">2</span><div><strong>Check the money.</strong> The dashboard shows today's collection by mode. An admin can open <em>Reports → Daily Collection</em> and tally cash against the drawer.</div></div>
                <div class="help-step"><span class="n">3</span><div><strong>Tomorrow: appointments and branches.</strong> Book from the patient page, then <em>Confirm</em> once the patient says yes. Press <em>Arrived</em> on the day. If you work in more than one branch, switch from the top bar: each branch has its own bills, visits and appointments, while patients are shared.</div></div>
                <div class="help-step"><span class="n">4</span><div><strong>Follow-ups.</strong> Missed ones are flagged overnight and show under <em>Follow-ups → Overdue / Missed</em>; one tap sends a WhatsApp message.</div></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card is-sticky-top">
            <div class="card-header">Quick answers</div>
            <div class="card-body small">
                <p><strong>I can't find the patient.</strong><br>Search by phone number, or just the first few letters of the name. If they're really new, use “Register”.</p>
                <p><strong>Two people share a phone.</strong><br>Each patient needs their own number in the system; use an alternate number for the second person, or ask the admin.</p>
                <p><strong>They paid more than the bill.</strong><br>The system won't accept more than what is due. Record only the bill amount.</p>
                <p><strong>Why can't I see a button?</strong><br>Your role doesn't allow it (for example, only admins cancel bills or reschedule EMI).</p>
                <p class="mb-0"><strong>Keyboard shortcuts.</strong><br><kbd>/</kbd> search patients · <kbd>Enter</kbd> next field in a bill · <kbd>Alt</kbd>+<kbd>A</kbd> add a bill row.</p>
            </div>
        </div>
    </div>
</div>
@endsection
