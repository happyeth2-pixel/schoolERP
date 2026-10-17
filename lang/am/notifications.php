<?php

// DRAFT — written for functional coverage, not yet checked by a native Amharic speaker.
// See resources/js/lang/review-status.json and LOCALIZATION.md.
return [

    'student_marked_absent' => [
        'title' => 'የመቅረት ምዝገባ',
        'body' => ':student በ:date ቀሪ ሆኖ ተመዝግቧል። ይህ ስህተት ከሆነ ትምህርት ቤቱ ያስተካክለዋል እናም ይነገርዎታል።',
    ],

    'attendance_corrected' => [
        'title' => 'የመገኘት እርማት',
        'body' => 'እርማት፦ የ:student መገኘት በ:date ከ:from ወደ :to ተቀይሯል። እባክዎ ቀደም ሲል የደረሰዎትን የመቅረት ማሳወቂያ ችላ ይበሉ።',
    ],

    'complaint_submitted' => [
        'title' => 'አዲስ ቅሬታ',
        'body' => '":subject" ከ:name',
    ],

    'announcement_published' => [
        'title' => 'አዲስ ማስታወቂያ',
        'body' => 'አዲስ ማስታወቂያ፦ :title',
    ],

    'complaint_updated' => [
        'title' => 'የቅሬታ ዝማኔ',
        'body' => 'ቅሬታዎ ":subject" አሁን :status ነው።',
        'status' => [
            'open' => 'ክፍት',
            'in_progress' => 'በሂደት ላይ',
            'resolved' => 'ተፈትቷል',
        ],
    ],

    'invoice_generated' => [
        'title' => 'አዲስ የክፍያ መጠየቂያ',
        'body' => 'ለ:student:period አዲስ የክፍያ መጠየቂያ፦ :amount ብር ይከፈላል።',
    ],

    'payment_refunded' => [
        'title_refunded' => 'ክፍያ ተመላሽ ተደርጓል',
        'title_reversed' => 'ክፍያ ተሽሯል',
        'body_refunded' => 'ለ:student የተከፈለ :amount ብር ተመላሽ ተደርጓል። ምክንያት፦ :reason',
        'body_reversed' => 'ለ:student የተከፈለ :amount ብር ተሽሯል። ምክንያት፦ :reason',
    ],

    'payment_rejected' => [
        'title' => 'ክፍያ ውድቅ ሆኗል',
        'body' => 'የ:amount ብር ክፍያዎ ውድቅ ሆኗል፦ :reason',
    ],

    'payment_verified' => [
        'title' => 'ክፍያ ተረጋግጧል',
        'body' => 'የ:amount ብር ክፍያዎ ተረጋግጧል። ደረሰኝ :receipt።',
    ],

    'low_stock' => [
        'title' => 'ዝቅተኛ ክምችት',
        'body' => ':item በ:store ወደ :quantity ወርዷል -- ከዳግም ማዘዣ መጠን ጋር ወይም ከዚያ በታች።',
    ],

    'requisition_submitted' => [
        'title' => 'ጥያቄ ለግምገማ',
        'body' => 'ጥያቄ :number ከ:requester ግምገማዎን ይፈልጋል።',
    ],

    'requisition_approved' => [
        'title' => 'ጥያቄ ጸድቋል',
        'body' => 'ጥያቄ :number ጸድቋል።',
    ],

    'requisition_rejected' => [
        'title' => 'ጥያቄ ውድቅ ተደርጓል',
        'body' => 'ጥያቄ :number ውድቅ ተደርጓል፦ :reason',
    ],

    'asset_assigned' => [
        'title' => 'ንብረት ለእርስዎ ተመድቧል',
        'body' => ':name (:tag) ለእርስዎ ተመድቧል።',
    ],

    'asset_repair_completed' => [
        'title' => 'ጥገና ተጠናቋል',
        'body_fixed' => ':name (:tag) ተጠግኖ ወደ እርስዎ ተመልሷል።',
        'body_damaged' => ':name (:tag) የተበላሸ ሆኖ ተገኝቷል እና ሊጠገን አልቻለም።',
    ],

    'purchase_request_submitted' => [
        'title' => 'የግዢ ጥያቄ ለግምገማ',
        'body' => 'የግዢ ጥያቄ :number ከ:requester ግምገማዎን ይፈልጋል።',
    ],

    'purchase_request_approved' => [
        'title' => 'የግዢ ጥያቄ ጸድቋል',
        'body' => 'የግዢ ጥያቄ :number ጸድቋል።',
    ],

    'purchase_request_rejected' => [
        'title' => 'የግዢ ጥያቄ ውድቅ ተደርጓል',
        'body' => 'የግዢ ጥያቄ :number ውድቅ ተደርጓል፦ :reason',
    ],

    'purchase_request_fulfilled' => [
        'title' => 'የግዢ ጥያቄ ተሟልቷል',
        'body' => 'በግዢ ጥያቄ :number ላይ ያለው ሁሉም ነገር ደርሷል።',
    ],

    'account_invitation' => [
        'subject' => 'የይለፍ ቃልዎን ለ:school ያዘጋጁ',
        'greeting' => 'ሰላም :name፣',
        'line_created' => 'በ:school ለእርስዎ መለያ በ:invited_by ተፈጥሯል።',
        'line_created_no_inviter' => 'በ:school ለእርስዎ መለያ ተፈጥሯል።',
        'action' => 'የይለፍ ቃል ያዘጋጁ',
        'line_expires' => 'ይህ አገናኝ አንድ ጊዜ ይሰራል እና በ:expires ያበቃል።',
        'line_ignore' => 'ይህን የማይጠብቁ ከሆነ ይህን ኢሜይል ችላ ማለት ይችላሉ።',
    ],

    'contract_expiring_soon' => [
        'title' => 'የቅጥር ውል በቅርቡ ያበቃል',
        'body' => 'የ:name ውል በ:date ያበቃል።',
    ],

    'loan_overdue' => [
        'title' => 'የላይብረሪ ውስኪት ጊዜው አልፏል',
        'body' => '":title" በ:borrower ተውስዶ የመመለሻ ቀኑ :date ነበር።',
    ],

    'attendance_correction_requested' => [
        'title' => 'የመገኘት እርማት ግምገማ ይፈልጋል',
        'body' => 'ለ:name የ:date መገኘት እርማት ተጠይቋል።',
    ],

    'attendance_correction_approved' => [
        'title' => 'የመገኘት እርማት ጸድቋል',
        'body' => 'ለ:name የ:date መገኘት ያቀረቡት እርማት ጸድቋል።',
    ],

    'attendance_correction_rejected' => [
        'title' => 'የመገኘት እርማት ውድቅ ሆኗል',
        'body' => 'ለ:name የ:date መገኘት ያቀረቡት እርማት ውድቅ ሆኗል።',
    ],

    'leave_request_submitted' => [
        'title' => 'የፈቃድ ጥያቄ ግምገማ ይፈልጋል',
        'body' => ':name ከ:start እስከ:end :type ጠይቋል።',
    ],

    'leave_request_approved' => [
        'title' => 'የፈቃድ ጥያቄ ጸድቋል',
        'body' => 'ከ:start እስከ:end ያቀረቡት የ:type ጥያቄ ጸድቋል።',
    ],

    'leave_request_rejected' => [
        'title' => 'የፈቃድ ጥያቄ ውድቅ ሆኗል',
        'body' => 'ከ:start እስከ:end ያቀረቡት የ:type ጥያቄ ውድቅ ሆኗል።',
    ],

    'performance_review_finalized' => [
        'title' => 'የሥራ አፈጻጸም ግምገማ ተጠናቅቋል',
        'body' => 'የ:cycle የሥራ አፈጻጸም ግምገማዎ ለእይታ ተዘጋጅቷል።',
    ],

    'payslip_ready' => [
        'title' => 'ደመወዝ ደረሰኝ ተዘጋጅቷል',
        'body' => 'የ:period ደመወዝ ደረሰኝዎ ለእይታ ተዘጋጅቷል።',
    ],

    'timetable_changed' => [
        'title' => 'የክፍል ጊዜ ሰሌዳ ተቀይሯል',
        'body' => 'ለ:subject ከ:section ጋር ያለዎት መርሃ ግብር ተጨምሯል ወይም ተቀይሯል።',
    ],

    'calendar_event_published' => [
        'title' => 'አዲስ የቀን መቁጠሪያ ክስተት',
        'body' => 'አዲስ የቀን መቁጠሪያ ክስተት፦ :title',
    ],

    'assignment_published' => [
        'title' => 'አዲስ ምደባ',
        'body' => 'አዲስ የ:subject ምደባ፦ :title',
    ],

    'assignment_due_soon' => [
        'title' => 'ምደባ ነገ ይደርሳል',
        'body' => 'የ:subject ምደባ «:title» ነገ ይደርሳል።',
    ],

];
