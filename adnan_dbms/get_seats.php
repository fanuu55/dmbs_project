<?php

require_once "db.php";

$movieId = intval($_GET["movie_id"]);

$sql = "
    SELECT seat_number
    FROM booking_seats
    WHERE movie_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $movieId);
$stmt->execute();

$result = $stmt->get_result();

$bookedSeats = [];

while ($row = $result->fetch_assoc()) {
    $bookedSeats[] = intval($row["seat_number"]);
}

header("Content-Type: application/json");

echo json_encode($bookedSeats);

$stmt->close();
$conn->close();

?>
