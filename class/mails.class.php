<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 */

/**
 * The built-in wording of every email that can be reworded from the setup page,
 * in one place. A setting left empty uses the text here.
 */
class OnboardingMails
{
	/**
	 * Member emails, by the setting that replaces them. Placeholders such as
	 * {firstname} are filled by OnboardingService::template().
	 */
	const MEMBER = array(
		'ONBOARDING_MAIL_ALREADY_BODY' => "Hi {firstname},\n\nSomeone started a membership signup with this email address, but you are already a member, so nothing was changed.",
		'ONBOARDING_MAIL_ALREADY_SUBJECT' => 'You are already a {org} member',
		'ONBOARDING_MAIL_LAPSED_BODY' => "Hi {firstname},\n\nWe did not receive your dues, so your {org} membership has ended and your badge access will be turned off. You are welcome back any time:\n\n{payment_url}",
		'ONBOARDING_MAIL_LAPSED_SUBJECT' => 'Your {org} membership has ended',
		'ONBOARDING_MAIL_REMINDER_BODY' => "Hi {firstname},\n\nYour {org} dues payment did not go through or was cancelled. To keep your membership and badge access, please set up dues again here:\n\n{payment_url}\n\nUse the same email address you joined with. Your membership will end in {days_left} days if we do not hear from you. If dues are a hardship, just reply to this email and we will work something out.",
		'ONBOARDING_MAIL_REMINDER_PAYPAL_BODY' => "Hi {firstname},\n\nWe have not received your {org} dues through PayPal since {paid_until}. Please check that your PayPal subscription is still active, or set up dues here instead:\n\n{payment_url}\n\nYour membership will end in {days_left} days if we do not hear from you. If dues are a hardship, just reply to this email and we will work something out.",
		'ONBOARDING_MAIL_REMINDER_SUBJECT' => 'Your {org} dues need attention',
		'ONBOARDING_MAIL_RESUME_BODY' => "Hi {firstname},\n\nThanks for starting your {org} membership. If you get interrupted, this link takes you back to where you left off:\n\n{link}\n\nIf you did not start a signup, you can ignore this email.",
		'ONBOARDING_MAIL_RESUME_SUBJECT' => 'Your {org} membership signup',
		'ONBOARDING_MAIL_TRAINED_BODY' => "Hi {firstname},\n\nThanks for your training fee. You are now on record as trained to use the {tool} ({zone}).\n\nYou can check what you are trained on at any time: {lookup_url}",
		'ONBOARDING_MAIL_TRAINED_SUBJECT' => 'You are trained on the {tool}',
		'ONBOARDING_MAIL_WELCOME_BODY' => "Hi {firstname},\n\nYour dues payment came through and your membership is active. Someone from the membership team will be in touch about your badge.",
		'ONBOARDING_MAIL_WELCOME_SUBJECT' => 'Welcome to {org}',
	);

	/**
	 * Training booking emails, by name: subject and text. Each can be replaced
	 * through ONBOARDING_MAIL_BK_<NAME>_SUBJECT and _BODY; sent by
	 * OnboardingBooking::mailTpl().
	 */
	const BOOKING = array(
		'sign' => array('Sign to book your {org} training', "Hi {firstname},\n\nBefore you can book a training we need {what} on file. It takes two minutes, on your phone or computer:\n\n{link}\n\nAfter signing you come straight back to the booking page.\n\nIf you did not ask for this, you can ignore this email."),
		'booked' => array('You are booked: {tool} training, {when} ({ref})', "Hi {firstname},\n\nYou are booked on the {tool} training with {trainer} on {when} at the shop. Paid: {paid}.\n\nBefore you come, read the guide: {wiki}\n\nYour trainer will confirm the session; you will hear from us if anything changes.\n\nNeed to cancel training? Click here (works until {cancel_hours} hours before):\n{link_cancel}\n\nCancelling at least {cancel_hours} hours before gets you a full refund or a new date."),
		'moved' => array('New date: {tool} training, {when} ({ref})', "Hi {firstname},\n\nYour {tool} training is now with {trainer} on {when}. Your trainer will confirm it.\n\nBefore you come, read the guide: {wiki}\n\nNeed to cancel training? Click here (works until {cancel_hours} hours before):\n{link_cancel}"),
		'lost_date' => array('Your {tool} training on {when} is cancelled ({ref})', "Hi {firstname},\n\nSorry: your {tool} training on {when} is not going ahead. {reason}\n\nYou have three choices:\n\nReschedule (pick a new date, no new fee):\n{link_move}\n\nRefund (your money back):\n{link_refund}\n\nOther (tell us what you need):\n{link_other}"),
		'refund_asked' => array('Refund on its way: {tool} training ({ref})', "Hi {firstname},\n\nYour {tool} training booking is cancelled and the treasurer has been asked to refund {paid}. Refunds go back to the card you paid with and can take a few days to show."),
		'refunded' => array('Refunded: {tool} training ({ref})', "Hi {firstname},\n\nWe have refunded your {tool} training fee ({paid}). It can take a few days to show on your statement."),
		'dayof' => array('Today: {tool} training at {when}', "Hi {firstname},\n\nSee you today: {tool} training with {trainer}, {when}.{minor_note}\n\nIf you have not yet, read the guide: {wiki}\n\nNeed to cancel training? Click here (works until {cancel_hours} hours before):\n{link_cancel}"),
		'trained' => array('You are trained on the {tool}', "Hi {firstname},\n\nYou are now on record as trained to use the {tool} ({zone}).\n\nYou can check what you are trained on at any time: {lookup_url}"),
		'missed_free' => array('We missed you at your {tool} training ({ref})', "Hi {firstname},\n\n{trainer} says you did not make it to the {tool} training on {when}. You can pick a new date once, at no charge, within {rebook_days} days:\n\n{link_move}\n\nSomething else? {link_other}"),
		'missed_final' => array('We missed you again at your {tool} training ({ref})', "Hi {firstname},\n\n{trainer} says you did not make it to the {tool} training on {when}. That was your free new date, so the fee is kept. You are welcome to book again on the website."),
		'trainee_followup' => array('Did your {tool} training happen? ({ref})', "Hi {firstname},\n\nYou were booked on the {tool} training with {trainer} on {when}, and we have not heard from your trainer yet.\n\nIf you were trained, there is nothing to do: you will get an email as soon as {trainer} records it.\n\nIf your trainer didn't show up, tell us here and we will sort out a new date or a refund:\n{link_no_trainer}"),
		'trainer_request' => array('{again}Training booked: {tool}, {when} ({ref})', "Hi {trainer},\n\n{trainee} ({kind}) booked the {tool} training with you on {when} and paid {price}.{minor_note}\n\nOn this session ({seats} seats taken):\n{roster}\n\nWill you be there?\n\nI'll be there:\n{link_confirm}\n\nI can't make it (they will be asked to pick a new date):\n{link_cant}\n\nIf you have not answered 24 hours before the session, it is cancelled and they are asked to pick a new date."),
		'trainer_added' => array('Another booking: {tool}, {when} ({ref})', "Hi {trainer},\n\n{trainee} ({kind}) joined your {tool} training on {when}. You already confirmed this session.{minor_note}\n\nOn this session ({seats} seats taken):\n{roster}\n\nIf you can no longer make it:\n{link_cant}"),
		'trainer_freed' => array('A seat is free: {tool}, {when}', "Hi {trainer},\n\n{trainee} is no longer coming to your {tool} training on {when}. Nothing for you to do."),
		'trainer_dayof' => array('Today: {tool} training at {when}', "Hi {trainer},\n\nYou are training today: {tool}, {when}.\n\nComing:\n{roster}\n\nIf you can no longer make it:\n{link_cant}"),
		'trainer_after' => array('{again}How did the {tool} training go? ({trainee}, {ref})', "Hi {trainer},\n\nThe {tool} training on {when} is over. For {trainee}, choose one:\n\n{trainee} showed up, and I performed the training:\n{link_trained}\n\n{trainee} did not show up, do not mark them as trained:\n{link_noshow}\n\nOther (leave a comment for the secretary):\n{link_other}"),
		'staff_noanswer' => array('Trainer did not confirm: {tool}, {when} ({ref})', "{trainer_full} did not confirm the {tool} training on {when} in time, so {trainee_full} was asked to pick a new date or ask for a refund.\n\n{card}"),
		'staff_refund' => array('Refund to make: {tool} training ({ref})', "{trainee_full} asked for a refund of their {tool} training on {when}. Paid: {paid}.\n\nComment: {comment}\n\nRefund it in Givebutter (Transactions, find the payment, Refund), then press Mark refunded on the booking in Dolibarr:\n{card}"),
		'staff_trainer_other' => array('Trainer comment on a training: {tool} ({ref})', "{trainer_full} left a comment on the {tool} training of {trainee_full} on {when}:\n\n{comment}\n\nSettle it on the booking in Dolibarr (Mark trained, Mark no-show, Send a new date link, Refund):\n{card}"),
		'staff_trainee_other' => array('Trainee needs something: {tool} training ({ref})', "{trainee_full} wrote about their {tool} training on {when}:\n\n{comment}\n\nThe booking in Dolibarr:\n{card}"),
		'staff_no_trainer' => array('Trainer did not show up: {tool} training ({ref})', "{trainee_full} says {trainer_full} did not show up for the {tool} training on {when}.\n\nComment: {comment}\n\nOffer them a new date or a refund from the booking in Dolibarr:\n{card}"),
		'staff_unanswered' => array('No answer from the trainer: {tool} training ({ref})', "{trainer_full} has not said whether {trainee_full} was trained on the {tool} on {when}. The trainee has been asked whether it happened.\n\nThe booking in Dolibarr:\n{card}"),
		'staff_rebook_unused' => array('Free rebooking not used: {tool} training ({ref})', "{trainee_full} has not picked a new date for their {tool} training in {rebook_days} days.\n\nDecide what to do (send the link again, refund, or cancel):\n{card}"),
		'class_created' => array('Tool training class: {tool}, {when} (code {code})', "A tool training class is set up: {tool} with {trainer}, {when}, {seats} seats.\n\nAttendees sign in on their phones at\n{class_url}\n\nor at the class page with the code {code}. The code works from 30 minutes before the start to 3 hours after the end. Print the QR code from the class page in Dolibarr:\n{card}"),
		'class_roster' => array('{again}Training class list to confirm: {tool}, {when}', "Hi {trainer},\n\n{count} people signed in to the {tool} class on {when}:\n\n{roster}\n\nConfirm who was trained (untick anyone who left early):\n{link_class}"),
		'staff_class_unanswered' => array('Training class list not confirmed: {tool}, {when}', "{trainer} has not confirmed who was trained at the {tool} class on {when} (code {code}).\n\n{roster}\n\nConfirm it on the class page in Dolibarr:\n{card}"),
		'staff_unmatched' => array('Training payment without a booking', "{who} paid {paid} on the training campaign (Givebutter {tx}), but no booking waiting for payment has that email and price. Usually they typed another email at Givebutter, or paid after their seat was let go.\n\nLink it to their booking under Members, Onboarding, Trainings, or refund it in Givebutter:\n{card}"),
		'wrong_amount' => array('Your training payment did not match your booking ({ref})', "Hi {firstname},\n\nWe received {paid_now} from you on Givebutter, but your {tool} training on {when} costs {price}, so the payment could not be matched to your booking and your seat is not confirmed yet.\n\nThe secretary has been told and will refund it or sort out the difference with you. You do not need to pay again."),
		'staff_wrong_amount' => array('Training paid with the wrong amount: {ref}', "{trainee_full} paid {paid_now} on Givebutter (transaction {tx}) for booking {ref}, {tool} on {when}, which costs {price}. The booking is not marked paid.\n\nEither refund the payment in Givebutter and ask them to book again, or, if you accept the amount, link the payment to the booking under Members, Onboarding, Trainings (Paid without a booking):\n{card}"),
		'spot_link' => array('Train someone now: your link', "Hi {trainer},\n\nHere is your link to start an on-the-spot training ({tools}). It works until {until}:\n\n{link}\n\nPick the tool and type the trainee's email address. They get an email asking whether they asked for the training; if they say yes, they sign the waiver and pay online, and you get an email to record the training once it is done. A request they have not paid within {hours} hours expires.\n\nIf you did not ask for this link, ignore it."),
		'spot_request' => array('{trainer_full} wants to train you on the {tool} now ({ref})', "Hi {firstname},\n\n{trainer_full} says you are with them at {org} and would like to train you on the {tool} now.\n\nDid you ask for this training?\n\nYes, that's me (sign the waiver if needed, then pay: {prices}):\n{link_yes}\n\nNo, I didn't ask for this:\n{link_no}\n\nThis request expires at {expires}, {hours} hours after it was sent. After that, ask your trainer to send a new one."),
		'spot_declined' => array('Training request declined: {tool} ({ref})', "Hi {trainer},\n\nThe person at {email} answered that they did not ask for a {tool} training, so the request is cancelled and nothing was charged. The secretary has been told.\n\nIf the email was mistyped, send a new request with the right address."),
		'staff_spot_declined' => array('On-the-spot training declined: {tool} ({ref})', "{trainer_full} started an on-the-spot {tool} training for {email}, and that person answered that they did not ask for it. The request is cancelled; nothing was charged.\n\nCheck with the trainer what happened:\n{card}"),
		'spot_expired' => array('Training request expired: {tool} ({ref})', "Hi {trainer},\n\nYour on-the-spot {tool} training request for {email} was not paid within {hours} hours, so it has expired. Nothing was charged.\n\nIf they still want the training, send a new request."),
		'spot_paid' => array('Paid: {tool} training with {trainer} ({ref})', "Hi {firstname},\n\nThank you: your payment of {paid} for the {tool} training with {trainer_full} arrived. Once the training is done, {trainer} records it and you get an email saying you are trained.\n\nThe guide for the {tool}: {wiki}\n\nIf something is wrong, tell us here:\n{link_other}"),
		'spot_trainer_paid' => array('Paid: {tool} training for {trainee} ({ref})', "Hi {trainer},\n\n{trainee} ({kind}) has paid {paid} for the {tool} training.{minor_note}\n\nWhen you have finished, choose one:\n\n{trainee} was trained:\n{link_trained}\n\nThe training did not happen, do not mark them as trained (they can pick a date or ask for a refund):\n{link_noshow}\n\nOther (leave a comment for the secretary):\n{link_other}"),
		'pick_date' => array('Pick a new date for your {tool} training ({ref})', "Hi {firstname},\n\nPick a new date for your {tool} training here:\n\n{link_move}"),
	);

	/**
	 * The wording to use: the setting if it is filled, else the built-in text.
	 *
	 * @param string $const Setting name, e.g. ONBOARDING_MAIL_WELCOME_BODY
	 * @return string
	 */
	public static function text($const)
	{
		return getDolGlobalString($const, self::builtIn($const));
	}

	/**
	 * @param string $const Setting name
	 * @return string The built-in text, or '' for a setting that is not an email
	 */
	public static function builtIn($const)
	{
		if (isset(self::MEMBER[$const])) {
			return self::MEMBER[$const];
		}
		if (preg_match('/^ONBOARDING_MAIL_BK_(\w+)_(SUBJECT|BODY)$/', $const, $m)) {
			$name = strtolower($m[1]);
			if (isset(self::BOOKING[$name])) {
				return self::BOOKING[$name][$m[2] == 'SUBJECT' ? 0 : 1];
			}
		}
		return '';
	}
}
