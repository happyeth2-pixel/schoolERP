<?php

// DRAFT — written for functional coverage, not yet checked by a native Tigrinya speaker.
// See resources/js/lang/review-status.json and LOCALIZATION.md.
return [

    'student_marked_absent' => [
        'title' => 'ምዝገባ ምጥፋእ',
        'body' => ':student ብ:date ከም ዝጠፍአ ተመዝጊቡ። እዚ ጌጋ እንተኾይኑ ቤት ትምህርቲ ከዐርዮ እዩ፣ ንስኹምውን ክትሕበሩ ኢኹም።',
    ],

    'attendance_corrected' => [
        'title' => 'ህላወ ተኣሪሙ',
        'body' => 'ምዕራይ፦ ህላወ :student ብ:date ካብ :from ናብ :to ተቐይሩ። በጃኹም ናይ ቀደም መጠንቀቕታ ምጥፋእ ኣይትገንዘቡ።',
    ],

    'complaint_submitted' => [
        'title' => 'ሓድሽ ጥርዓን',
        'body' => '":subject" ካብ :name',
    ],

    'announcement_published' => [
        'title' => 'ሓድሽ ኣዋጅ',
        'body' => 'ሓድሽ ኣዋጅ፦ :title',
    ],

    'complaint_updated' => [
        'title' => 'ዝማነ ጥርዓን',
        'body' => 'ጥርዓንኩም ":subject" ሕጂ :status እዩ።',
        'status' => [
            'open' => 'ክፉት',
            'in_progress' => 'ኣብ ምዕባለ ኣሎ',
            'resolved' => 'ተፈቲሑ',
        ],
    ],

    'invoice_generated' => [
        'title' => 'ሓድሽ ፋክቱራ',
        'body' => 'ሓድሽ ፋክቱራ ን:student:period: :amount ብር ክኽፈል ኣለዎ።',
    ],

    'payment_refunded' => [
        'title_refunded' => 'ክፍሊት ተመሊሱ',
        'title_reversed' => 'ክፍሊት ተሰሪዙ',
        'body_refunded' => ':amount ብር ንክፍሊት :student ተመሊሱ። ምኽንያት፦ :reason',
        'body_reversed' => ':amount ብር ንክፍሊት :student ተሰሪዙ። ምኽንያት፦ :reason',
    ],

    'payment_rejected' => [
        'title' => 'ክፍሊት ተነጺጉ',
        'body' => 'ክፍሊትኩም ናይ :amount ብር ተነጺጉ፦ :reason',
    ],

    'payment_verified' => [
        'title' => 'ክፍሊት ተረጋጊጹ',
        'body' => 'ክፍሊትኩም ናይ :amount ብር ተረጋጊጹ። ቅብሊት :receipt።',
    ],

    'low_stock' => [
        'title' => 'ትሑት ክምችት',
        'body' => ':item ኣብ :store ናብ :quantity ወሪዱ -- ኣብ ወይ ትሕቲ መጠን ዳግማይ ትእዛዝ።',
    ],

    'requisition_submitted' => [
        'title' => 'ጥያቐ ንግምጋም',
        'body' => 'ጥያቐ :number ካብ :requester ግምገማኻ የድልዮ።',
    ],

    'requisition_approved' => [
        'title' => 'ጥያቐ ጸዲቑ',
        'body' => 'ጥያቐ :number ጸዲቑ።',
    ],

    'requisition_rejected' => [
        'title' => 'ጥያቐ ተነጺጉ',
        'body' => 'ጥያቐ :number ተነጺጉ፦ :reason',
    ],

    'asset_assigned' => [
        'title' => 'ንብረት ንዓኻ ተመዲቡ',
        'body' => ':name (:tag) ንዓኻ ተመዲቡ።',
    ],

    'asset_repair_completed' => [
        'title' => 'ጽገና ተዛዚሙ',
        'body_fixed' => ':name (:tag) ተዓሪዩ ናብኡ ተመሊሱ።',
        'body_damaged' => ':name (:tag) ተበላሽዩ ተረኺቡ፣ ክዕረ ኣይካኣለን።',
    ],

    'purchase_request_submitted' => [
        'title' => 'ጥያቐ ግዢ ንግምጋም',
        'body' => 'ጥያቐ ግዢ :number ካብ :requester ግምገማኻ የድልዮ።',
    ],

    'purchase_request_approved' => [
        'title' => 'ጥያቐ ግዢ ጸዲቑ',
        'body' => 'ጥያቐ ግዢ :number ጸዲቑ።',
    ],

    'purchase_request_rejected' => [
        'title' => 'ጥያቐ ግዢ ተነጺጉ',
        'body' => 'ጥያቐ ግዢ :number ተነጺጉ፦ :reason',
    ],

    'purchase_request_fulfilled' => [
        'title' => 'ጥያቐ ግዢ ተማልአ',
        'body' => 'ኣብ ጥያቐ ግዢ :number ዘሎ ኩሉ ሕጂ በጺሑ።',
    ],

    'account_invitation' => [
        'subject' => 'ናይ ይለፍ ቃልኩም ን:school ኣዳልዉ',
        'greeting' => 'ሰላም :name፣',
        'line_created' => 'ኣብ :school ንዓኹም ሕሳብ ብ:invited_by ተፈጢሩ።',
        'line_created_no_inviter' => 'ኣብ :school ንዓኹም ሕሳብ ተፈጢሩ።',
        'action' => 'ናይ ይለፍ ቃል ኣዳልዉ',
        'line_expires' => 'እዚ ሊንክ እዚ ሓደ ጊዜ ጥራይ ይሰርሕ፣ ኣብ :expires ኸኣ ይውዳእ።',
        'line_ignore' => 'እዚ ዘይትጽበዩዎ እንተኾይኑ፣ ነዚ ኢመይል እዚ ክትግድፍዎ ትኽእሉ ኢኹም።',
    ],

    'contract_expiring_soon' => [
        'title' => 'ውዕል ስራሕ ኣብ ቀረባ ግዜ ክውዳእ እዩ',
        'body' => 'ውዕል :name ኣብ :date ይውዳእ።',
    ],

    'loan_overdue' => [
        'title' => 'ናይ ቤተ-መጻሕፍቲ ልቓሕ ግዜኡ ሓሊፉ',
        'body' => '":title" ብ:borrower ተለቂሑ ናይ ምምላስ ግዜኡ :date ነበረ።',
    ],

    'attendance_correction_requested' => [
        'title' => 'ዕርማት ህላወ ግምገማ የድልዮ',
        'body' => 'ንህላወ :name ናይ :date ዕርማት ተሓቲቱ።',
    ],

    'attendance_correction_approved' => [
        'title' => 'ዕርማት ህላወ ጸዲቑ',
        'body' => 'ዕርማትኩም ንህላወ :name ናይ :date ጸዲቑ።',
    ],

    'attendance_correction_rejected' => [
        'title' => 'ዕርማት ህላወ ተነጺጉ',
        'body' => 'ዕርማትኩም ንህላወ :name ናይ :date ተነጺጉ።',
    ],

    'leave_request_submitted' => [
        'title' => 'ሕቶ ፈቓድ ግምገማ የድልዮ',
        'body' => ':name ካብ :start ክሳብ :end :type ሓቲቱ።',
    ],

    'leave_request_approved' => [
        'title' => 'ሕቶ ፈቓድ ጸዲቑ',
        'body' => 'ሕቶኹም :type ካብ :start ክሳብ :end ጸዲቑ።',
    ],

    'leave_request_rejected' => [
        'title' => 'ሕቶ ፈቓድ ተነጺጉ',
        'body' => 'ሕቶኹም :type ካብ :start ክሳብ :end ተነጺጉ።',
    ],

    'performance_review_finalized' => [
        'title' => 'ግምገማ ኣፈጻጽማ ዕማም ተዛዚሙ',
        'body' => 'ግምገማ ኣፈጻጽማ ዕማምካ ናይ :cycle ንምርኣይ ተዳልዩ ኣሎ።',
    ],

    'payslip_ready' => [
        'title' => 'ናይ ደሞዝ ወረቐት ተዳልዩ',
        'body' => 'ናይ ደሞዝ ወረቐትካ ናይ :period ንምርኣይ ተዳልዩ ኣሎ።',
    ],

    'timetable_changed' => [
        'title' => 'መደብ ክፍሊ ተቐይሩ',
        'body' => 'መደብካ ናይ :subject ምስ :section ተወሲኹ ወይ ተቐይሩ።',
    ],

    'calendar_event_published' => [
        'title' => 'ሓድሽ ናይ ካላንደር ፍጻመ',
        'body' => 'ሓድሽ ናይ ካላንደር ፍጻመ፦ :title',
    ],

    'assignment_published' => [
        'title' => 'ሓድሽ ምደባ',
        'body' => 'ሓድሽ ናይ :subject ምደባ፦ :title',
    ],

    'assignment_due_soon' => [
        'title' => 'ምደባ ጽባሕ ይበቅዕ',
        'body' => 'ናይ :subject ምደባ «:title» ጽባሕ ይበቅዕ።',
    ],

];
