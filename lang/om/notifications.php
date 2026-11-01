<?php

// DRAFT — written for functional coverage, not yet checked by a native Afaan Oromo speaker.
// See resources/js/lang/review-status.json and LOCALIZATION.md.
return [

    'student_marked_absent_male' => [
        'title' => 'Galmeen Dhabamuu',
        'body' => ":student guyyaa :date hin argamne jedhamee galmaa'e. Kun dogoggora yoo ta'e, mana barumsichaatu sirreessa, isinis ni beeksifamtu.",
    ],

    'student_marked_absent_female' => [
        'title' => 'Galmeen Dhabamuu',
        'body' => ":student guyyaa :date hin argamne jedhamee galmaa'e. Kun dogoggora yoo ta'e, mana barumsichaatu sirreessa, isinis ni beeksifamtu.",
    ],

    'attendance_corrected' => [
        'title' => 'Argamuun Sirreeffame',
        'body' => 'Sirreeffama: Argamuun :student guyyaa :date irratti :from irraa gara :to tti jijjiirameera. Beeksisa dhabamuu kanaan dura ergame maal iyyuu hin taasisinaa.',
    ],

    'complaint_submitted' => [
        'title' => 'Komii Haaraa',
        'body' => '":subject" :name irraa',
    ],

    'announcement_published' => [
        'title' => 'Beeksisa haaraa',
        'body' => 'Beeksisa haaraa: :title',
    ],

    'contact_inquiry_received' => [
        'title' => 'Ergaa quunnamtii haaraa',
        'body' => ':name (:phone) irraa',
    ],

    'complaint_updated' => [
        'title' => 'Haaromsa Komii',
        'body' => 'Komiin keessan ":subject" amma :status.',
        'status' => [
            'open' => 'banaa',
            'in_progress' => 'adeemsa irra',
            'resolved' => 'furameera',
        ],
    ],

    'invoice_generated' => [
        'title' => 'Invoice Haaraa',
        'body' => 'Invoice haaraa :student:period tiif: Qarshii :amount kaffalamuu qaba.',
    ],

    'payment_refunded' => [
        'title_refunded' => 'Kaffaltiin Deebifameera',
        'title_reversed' => 'Kaffaltiin Haqameera',
        'body_refunded' => 'Qarshiin :amount kaffaltii :student tiif kaffalame deebifameera. Sababa: :reason',
        'body_reversed' => 'Qarshiin :amount kaffaltii :student tiif kaffalame haqameera. Sababa: :reason',
    ],

    'payment_rejected' => [
        'title' => 'Kaffaltiin Kufeera',
        'body' => 'Kaffaltiin keessan qarshii :amount kufeera: :reason',
    ],

    'payment_verified' => [
        'title' => "Kaffaltiin Mirkanaa'eera",
        'body' => "Kaffaltiin keessan qarshii :amount mirkanaa'eera. Ragaan kaffaltii :receipt.",
    ],

    'low_stock' => [
        'title' => 'Kuusaa Xiqqaa',
        'body' => ':item :store keessatti :quantity gaddeera -- sadarkaa deebi\'anii ajajuu ykn gadi.',
    ],

    'requisition_submitted' => [
        'title' => 'Gaaffiin ilaalcha barbaada',
        'body' => 'Gaaffiin :number :requester irraa ilaalcha kee barbaada.',
    ],

    'requisition_approved' => [
        'title' => 'Gaaffiin ni fudhatame',
        'body' => 'Gaaffiin :number ni fudhatame.',
    ],

    'requisition_rejected' => [
        'title' => 'Gaaffiin ni dide',
        'body' => 'Gaaffiin :number ni dide: :reason',
    ],

    'asset_assigned' => [
        'title' => 'Qabeenyi siif kenname',
        'body' => ':name (:tag) siif kenname.',
    ],

    'asset_repair_completed' => [
        'title' => 'Suphaan xumurameera',
        'body_fixed' => ':name (:tag) suphamee gara keetti deebi\'eera.',
        'body_damaged' => ':name (:tag) miidhamee argame, suphamuu hin dandeenye.',
    ],

    'purchase_request_submitted' => [
        'title' => 'Gaaffiin bittaa ilaalcha barbaada',
        'body' => 'Gaaffiin bittaa :number :requester irraa ilaalcha kee barbaada.',
    ],

    'purchase_request_approved' => [
        'title' => 'Gaaffiin bittaa ni fudhatame',
        'body' => 'Gaaffiin bittaa :number ni fudhatame.',
    ],

    'purchase_request_rejected' => [
        'title' => 'Gaaffiin bittaa ni dide',
        'body' => 'Gaaffiin bittaa :number ni dide: :reason',
    ],

    'purchase_request_fulfilled' => [
        'title' => 'Gaaffiin bittaa guutameera',
        'body' => 'Wanti gaaffii bittaa :number irra jiru hundi amma gaheera.',
    ],

    'account_invitation' => [
        'subject' => 'Jecha Iccitii Keessan :school tiif Qopheessaa',
        'greeting' => 'Akkam :name,',
        'line_created' => 'Herregni :school tti isiniif :invited_by n uumameera.',
        'line_created_no_inviter' => 'Herregni :school tti isiniif uumameera.',
        'action' => 'Jecha Iccitii Qopheessaa',
        'line_expires' => 'Hidhannoon kun yeroo tokko qofa hojjeta, guyyaa :expires irratti ni dhaabbata.',
        'line_ignore' => "Yoo kana hin eegin ta'e, imeelii kana dagachuu dandeessu.",
    ],

    'contract_expiring_soon' => [
        'title' => 'Waligaltee Hojii dhiyeenyatti dhaabbata',
        'body' => 'Waligalteen :name guyyaa :date irratti dhaabbata.',
    ],

    'loan_overdue' => [
        'title' => 'Liqii kitaaba yeroon darbe',
        'body' => '":title" kan :borrower liqeeffate guyyaa :date deebi\'uu qaba ture.',
    ],

    'attendance_correction_requested' => [
        'title' => 'Sirreeffamni argamaa ilaalcha barbaada',
        'body' => 'Sirreeffamni argamaa :name guyyaa :date iif gaafatameera.',
    ],

    'attendance_correction_approved' => [
        'title' => 'Sirreeffamni argamaa mirkanaa\'eera',
        'body' => 'Sirreeffamni argamaa :name guyyaa :date keessan mirkanaa\'eera.',
    ],

    'attendance_correction_rejected' => [
        'title' => 'Sirreeffamni argamaa kuffifameera',
        'body' => 'Sirreeffamni argamaa :name guyyaa :date keessan kuffifameera.',
    ],

    'leave_request_submitted' => [
        'title' => 'Gaaffiin boqonnaa ilaalcha barbaada',
        'body' => ':name guyyaa :start hanga :end :type gaafateera.',
    ],

    'leave_request_approved' => [
        'title' => 'Gaaffiin boqonnaa mirkanaa\'eera',
        'body' => 'Gaaffiin keessan :type guyyaa :start hanga :end mirkanaa\'eera.',
    ],

    'leave_request_rejected' => [
        'title' => 'Gaaffiin boqonnaa kuffifameera',
        'body' => 'Gaaffiin keessan :type guyyaa :start hanga :end kuffifameera.',
    ],

    'performance_review_finalized' => [
        'title' => 'Madaalli raawwii hojii xumurameera',
        'body' => 'Madaalliin raawwii hojii keessan kan :cycle ilaaluuf qophaa\'eera.',
    ],

    'payslip_ready' => [
        'title' => 'Baafanni mindaa qophaa\'eera',
        'body' => 'Baafannaan mindaa keessan kan :period ilaaluuf qophaa\'eera.',
    ],

    'timetable_changed' => [
        'title' => 'Sagantaan kutaa jijjiirameera',
        'body' => 'Sagantaan keessan kan :subject kutaa :section waliin dabalameera ykn jijjiirameera.',
    ],

    'calendar_event_published' => [
        'title' => 'Taateen Kalandarii Haaraa',
        'body' => 'Taateen Kalandarii Haaraa: :title',
    ],

    'assignment_published' => [
        'title' => 'Hojii Manaa Haaraa',
        'body' => 'Hojiin manaa :subject haaraa: :title',
    ],

    'assignment_due_soon' => [
        'title' => 'Hojiin Manaa Boru Dhihaata',
        'body' => 'Hojiin manaa :subject ":title" boru dhihaata.',
    ],

    'new_parent_message' => [
        'title' => 'Ergaa Haaraa',
        'body' => ':sender waa\'ee :student ergaa ergeera.',
    ],

    'section_capacity_exceeded' => [
        'title' => 'Kutaan Dandeettii Isaa Caalee',
        'body' => ':section amma barattoota :occupied qaba, dandeettii isaa :capacity caalee.',
    ],

    'invoice_overdue' => [
        'title' => 'Invoisiin Tureera',
        'body' => 'Invoisii :student:period (:balance ETB hin kaffalamin) guyyaa :date irratti kaffalamuu qaba ture. Maatii hordofaa.',
    ],

    'mark_batch_submitted' => [
        'title' => 'Firiin Mirkaneeffama Eegaa Jira',
        'body' => ':teacher firii :subject kan :section (:exam) mirkaneeffamaaf galcheera.',
    ],

    'mark_batch_published' => [
        'title' => 'Firiin Qormaataa Haaraan Maxxanfameera',
        'body' => 'Firiin :subject :student kan :exam amma maxxanfameera.',
    ],

];
