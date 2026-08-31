<?php

require_once "db.php";

header("Content-Type: application/json");

$name = $_POST["name"] ?? "";
$phone = $_POST["phone"] ?? "";
$email = $_POST["email"] ?? "";
$movieId = intval($_POST["movie_id"] ?? 0);
$seats = $_POST["seats"] ?? [];
$paymentMethod = $_POST["payment_method"] ?? "";

if (
    empty($name) ||
    empty($phone) ||
    empty($email) ||
    $movieId <= 0 ||
    empty($seats)
) {
    echo json_encode([
        "success" => false,
        "message" => "Please fill all required details."
    ]);
    exit;
}

$conn->begin_transaction();

try {

    // ---------------------------
    // 1. Get movie
    // ---------------------------

    $stmt = $conn->prepare(
        "SELECT title, price FROM movies WHERE id = ?"
    );

    $stmt->bind_param("i", $movieId);
    $stmt->execute();

    $movieResult = $stmt->get_result();

    if ($movieResult->num_rows === 0) {
        throw new Exception("Movie not found.");
    }

    $movie = $movieResult->fetch_assoc();

    $movieTitle = $movie["title"];
    $ticketPrice = floatval($movie["price"]);

    // ---------------------------
    // 2. Check seats
    // ---------------------------

    $seatCheck = $conn->prepare(
        "SELECT seat_number
         FROM booking_seats
         WHERE movie_id = ?
         AND seat_number = ?"
    );

    foreach ($seats as $seat) {

        $seat = intval($seat);

        $seatCheck->bind_param(
            "ii",
            $movieId,
            $seat
        );

        $seatCheck->execute();

        $seatResult = $seatCheck->get_result();

        if ($seatResult->num_rows > 0) {
            throw new Exception(
                "Seat $seat is already booked."
            );
        }
    }

    // ---------------------------
    // 3. Find customer
    // ---------------------------

    $customerSearch = $conn->prepare(
        "SELECT id FROM customers WHERE email = ?"
    );

    $customerSearch->bind_param("s", $email);
    $customerSearch->execute();

    $customerResult = $customerSearch->get_result();

    if ($customerResult->num_rows > 0) {

        $customer = $customerResult->fetch_assoc();

        $customerId = $customer["id"];

        $updateCustomer = $conn->prepare(
            "UPDATE customers
             SET name = ?, phone = ?
             WHERE id = ?"
        );

        $updateCustomer->bind_param(
            "ssi",
            $name,
            $phone,
            $customerId
        );

        $updateCustomer->execute();

    } else {

        $customerInsert = $conn->prepare(
            "INSERT INTO customers
             (name, phone, email)
             VALUES (?, ?, ?)"
        );

        $customerInsert->bind_param(
            "sss",
            $name,
            $phone,
            $email
        );

        $customerInsert->execute();

        $customerId = $conn->insert_id;
    }

    // ---------------------------
    // 4. Calculate total
    // ---------------------------

    $totalAmount =
        count($seats) * $ticketPrice;

    // ---------------------------
    // 5. Create booking
    // ---------------------------

    $bookingInsert = $conn->prepare(
        "INSERT INTO bookings
         (customer_id, movie_id, total_amount)
         VALUES (?, ?, ?)"
    );

    $bookingInsert->bind_param(
        "iid",
        $customerId,
        $movieId,
        $totalAmount
    );

    $bookingInsert->execute();

    $bookingId = $conn->insert_id;

    // ---------------------------
    // 6. Insert seats
    // ---------------------------

    $seatInsert = $conn->prepare(
        "INSERT INTO booking_seats
         (booking_id, movie_id, seat_number)
         VALUES (?, ?, ?)"
    );

    foreach ($seats as $seat) {

        $seat = intval($seat);

        $seatInsert->bind_param(
            "iii",
            $bookingId,
            $movieId,
            $seat
        );

        $seatInsert->execute();
    }

    // ---------------------------
    // 7. Insert payment
    // ---------------------------

    $paymentInsert = $conn->prepare(
        "INSERT INTO payments
         (booking_id, amount, payment_status)
         VALUES (?, ?, ?)"
    );

    $paymentStatus = "Paid";

    $paymentInsert->bind_param(
        "ids",
        $bookingId,
        $totalAmount,
        $paymentStatus
    );

    $paymentInsert->execute();

    // ---------------------------
    // 8. Commit
    // ---------------------------

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Booking successful!",
        "booking_id" => $bookingId,
        "movie" => $movieTitle,
        "seats" => $seats,
        "total" => $totalAmount
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}

$conn->close();

?>
