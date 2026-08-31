<?php

/* =====================================================
   DATABASE CONNECTION
===================================================== */

$host = "localhost";
$username = "root";
$password = "";
$database = "movie_booking";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

if ($conn->connect_error) {
    die(
        "<h2>Database Connection Failed</h2>" .
        $conn->connect_error
    );
}

$conn->set_charset("utf8mb4");


/* =====================================================
   HANDLE AJAX REQUESTS
===================================================== */

if (isset($_GET["action"])) {

    header("Content-Type: application/json");

    $action = $_GET["action"];


    /* =================================================
       GET MOVIES
    ================================================= */

    if ($action === "movies") {

        $result = $conn->query(
            "SELECT * FROM movies ORDER BY id"
        );

        if (!$result) {

            echo json_encode([
                "success" => false,
                "message" => $conn->error
            ]);

            exit;
        }

        $movies = [];

        while ($row = $result->fetch_assoc()) {
            $movies[] = $row;
        }

        echo json_encode($movies);

        exit;
    }


    /* =================================================
       GET BOOKED SEATS
    ================================================= */

    if ($action === "seats") {

        $movieId = intval(
            $_GET["movie_id"] ?? 0
        );

        $stmt = $conn->prepare(
            "SELECT seat_number
             FROM booking_seats
             WHERE movie_id = ?"
        );

        $stmt->bind_param(
            "i",
            $movieId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $bookedSeats = [];

        while ($row = $result->fetch_assoc()) {

            $bookedSeats[] =
                intval($row["seat_number"]);
        }

        echo json_encode($bookedSeats);

        exit;
    }


    /* =================================================
       BOOK TICKETS
    ================================================= */

    if ($action === "book") {

        $name =
            trim($_POST["name"] ?? "");

        $phone =
            trim($_POST["phone"] ?? "");

        $email =
            trim($_POST["email"] ?? "");

        $movieId =
            intval($_POST["movie_id"] ?? 0);

        $seats =
            $_POST["seats"] ?? [];

        $showTime =
            $_POST["show_time"] ?? "";

        $paymentMethod =
            $_POST["payment_method"] ?? "";


        /* ---------------------------------------------
           VALIDATION
        --------------------------------------------- */

        if (
            $name === "" ||
            $phone === "" ||
            $email === "" ||
            $movieId <= 0 ||
            empty($seats)
        ) {

            echo json_encode([
                "success" => false,
                "message" =>
                    "Please fill all details and select seats."
            ]);

            exit;
        }


        /* ---------------------------------------------
           GET MOVIE
        --------------------------------------------- */

        $stmt = $conn->prepare(
            "SELECT title, price
             FROM movies
             WHERE id = ?"
        );

        $stmt->bind_param(
            "i",
            $movieId
        );

        $stmt->execute();

        $movieResult =
            $stmt->get_result();

        if ($movieResult->num_rows === 0) {

            echo json_encode([
                "success" => false,
                "message" => "Movie not found."
            ]);

            exit;
        }

        $movie =
            $movieResult->fetch_assoc();

        $movieTitle =
            $movie["title"];

        $price =
            floatval($movie["price"]);


        /* ---------------------------------------------
           CLEAN SEATS
        --------------------------------------------- */

        $cleanSeats = [];

        foreach ($seats as $seat) {

            $seat = intval($seat);

            if ($seat >= 1 && $seat <= 32) {

                if (!in_array(
                    $seat,
                    $cleanSeats
                )) {

                    $cleanSeats[] = $seat;
                }
            }
        }

        if (empty($cleanSeats)) {

            echo json_encode([
                "success" => false,
                "message" => "Invalid seats."
            ]);

            exit;
        }


        /* ---------------------------------------------
           START TRANSACTION
        --------------------------------------------- */

        $conn->begin_transaction();

        try {


            /* -----------------------------------------
               CHECK SEATS
            ----------------------------------------- */

            $seatCheck = $conn->prepare(
                "SELECT seat_number
                 FROM booking_seats
                 WHERE movie_id = ?
                 AND seat_number = ?"
            );

            foreach ($cleanSeats as $seat) {

                $seatCheck->bind_param(
                    "ii",
                    $movieId,
                    $seat
                );

                $seatCheck->execute();

                $seatResult =
                    $seatCheck->get_result();

                if ($seatResult->num_rows > 0) {

                    throw new Exception(
                        "Seat $seat is already booked."
                    );
                }
            }


            /* -----------------------------------------
               FIND CUSTOMER
            ----------------------------------------- */

            $customerSearch =
                $conn->prepare(
                    "SELECT id
                     FROM customers
                     WHERE email = ?"
                );

            $customerSearch->bind_param(
                "s",
                $email
            );

            $customerSearch->execute();

            $customerResult =
                $customerSearch->get_result();


            if ($customerResult->num_rows > 0) {

                $customer =
                    $customerResult->fetch_assoc();

                $customerId =
                    $customer["id"];


                /* Update customer */

                $update =
                    $conn->prepare(
                        "UPDATE customers
                         SET name = ?, phone = ?
                         WHERE id = ?"
                    );

                $update->bind_param(
                    "ssi",
                    $name,
                    $phone,
                    $customerId
                );

                $update->execute();


            } else {


                /* Create customer */

                $customerInsert =
                    $conn->prepare(
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

                $customerId =
                    $conn->insert_id;
            }


            /* -----------------------------------------
               CALCULATE TOTAL
            ----------------------------------------- */

            $total =
                count($cleanSeats) * $price;


            /* -----------------------------------------
               CREATE BOOKING
            ----------------------------------------- */

            $bookingInsert =
                $conn->prepare(
                    "INSERT INTO bookings
                     (
                        customer_id,
                        movie_id,
                        total_amount,
                        show_time,
                        payment_method
                     )
                     VALUES (?, ?, ?, ?, ?)"
                );

            $bookingInsert->bind_param(
                "iidss",
                $customerId,
                $movieId,
                $total,
                $showTime,
                $paymentMethod
            );

            $bookingInsert->execute();

            $bookingId =
                $conn->insert_id;


            /* -----------------------------------------
               INSERT SEATS
            ----------------------------------------- */

            $seatInsert =
                $conn->prepare(
                    "INSERT INTO booking_seats
                     (
                        booking_id,
                        movie_id,
                        seat_number
                     )
                     VALUES (?, ?, ?)"
                );

            foreach ($cleanSeats as $seat) {

                $seatInsert->bind_param(
                    "iii",
                    $bookingId,
                    $movieId,
                    $seat
                );

                $seatInsert->execute();
            }


            /* -----------------------------------------
               PAYMENT
            ----------------------------------------- */

            $paymentStatus = "Paid";

            $paymentInsert =
                $conn->prepare(
                    "INSERT INTO payments
                     (
                        booking_id,
                        amount,
                        payment_status
                     )
                     VALUES (?, ?, ?)"
                );

            $paymentInsert->bind_param(
                "ids",
                $bookingId,
                $total,
                $paymentStatus
            );

            $paymentInsert->execute();


            /* -----------------------------------------
               COMMIT
            ----------------------------------------- */

            $conn->commit();


            echo json_encode([

                "success" => true,

                "booking_id" =>
                    $bookingId,

                "movie" =>
                    $movieTitle,

                "seats" =>
                    $cleanSeats,

                "total" =>
                    $total

            ]);

        } catch (Exception $e) {

            $conn->rollback();

            echo json_encode([

                "success" => false,

                "message" =>
                    $e->getMessage()

            ]);
        }

        exit;
    }


    /* =================================================
       UNKNOWN ACTION
    ================================================= */

    echo json_encode([
        "success" => false,
        "message" => "Invalid action."
    ]);

    exit;
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        CineTicket - Movie Ticket Booking
    </title>

    <link
        rel="stylesheet"
        href="index.css"
    >

</head>


<body>


<header>

    <h1>🎬 CineTicket</h1>

    <span>
        DBMS Microproject UI
    </span>

</header>


<div class="container">


    <!-- =========================================
         MOVIES
    ========================================== -->

    <section id="movie-section">

        <h2>Now Showing</h2>

        <div
            class="movie-grid"
            id="movieGrid"
        >

            <p>Loading movies...</p>

        </div>

    </section>



    <!-- =========================================
         BOOKING
    ========================================== -->

    <section
        id="booking-section"
        class="booking-section"
        style="display:none;"
    >

        <h2 id="selectedMovieTitle">
            Book Tickets
        </h2>


        <div
            style="
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:20px;
            "
        >


            <!-- CUSTOMER -->

            <div>

                <h3>
                    1. Customer Details
                </h3>

                <br>


                <div class="form-group">

                    <label>
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="custName"
                        placeholder="Enter your name"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Phone Number
                    </label>

                    <input
                        type="tel"
                        id="custPhone"
                        placeholder="Enter phone number"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Email Address
                    </label>

                    <input
                        type="email"
                        id="custEmail"
                        placeholder="Enter email"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Select Show Time
                    </label>

                    <select id="showTimeSelect">

                        <option value="10:00 AM - Screen 1">
                            10:00 AM (Screen 1)
                        </option>

                        <option value="02:30 PM - Screen 1">
                            02:30 PM (Screen 1)
                        </option>

                        <option value="06:45 PM - Screen 2">
                            06:45 PM (Screen 2)
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Payment Method
                    </label>

                    <select id="paymentMethod">

                        <option value="UPI">
                            UPI / Google Pay
                        </option>

                        <option value="Card">
                            Credit/Debit Card
                        </option>

                        <option value="NetBanking">
                            Net Banking
                        </option>

                    </select>

                </div>

            </div>



            <!-- SEATS -->

            <div>

                <h3>
                    2. Select Seats
                </h3>


                <div class="screen-container">

                    <div class="screen"></div>

                    <div class="screen-label">
                        SCREEN THIS WAY
                    </div>


                    <div
                        class="seats-grid"
                        id="seatsGrid"
                    >

                    </div>


                    <div class="legend">


                        <div class="legend-item">

                            <div
                                class="legend-box"
                                style="background:#444;"
                            ></div>

                            Available

                        </div>


                        <div class="legend-item">

                            <div
                                class="legend-box"
                                style="background:var(--accent-green);"
                            ></div>

                            Selected

                        </div>


                        <div class="legend-item">

                            <div
                                class="legend-box"
                                style="background:#222;"
                            ></div>

                            Booked

                        </div>


                    </div>

                </div>

            </div>

        </div>



        <!-- SUMMARY -->

        <div class="summary-box">


            <div class="summary-row">

                <span>
                    Selected Seats:
                </span>

                <strong id="selectedSeatsText">
                    None
                </strong>

            </div>


            <div class="summary-row">

                <span>
                    Ticket Price (per seat):
                </span>

                <span>
                    ₹<span id="ticketPrice">0</span>
                </span>

            </div>


            <hr
                style="
                    border-color:#444;
                    margin:10px 0;
                "
            >


            <div
                class="summary-row"
                style="
                    font-size:16px;
                    color:var(--accent-green);
                "
            >

                <span>
                    Total Amount:
                </span>

                <strong>
                    ₹<span id="totalAmount">0</span>
                </strong>

            </div>


        </div>


        <br>


        <button
            class="btn"
            onclick="confirmBooking()"
        >
            Confirm & Pay
        </button>


        <button
            class="btn"
            onclick="cancelBooking()"
            style="
                background-color:#555;
                margin-top:10px;
            "
        >
            Cancel
        </button>


    </section>

</div>



<script>

/* =====================================================
   VARIABLES
===================================================== */

let movies = [];

let selectedMovie = null;

let selectedSeats = [];


/* =====================================================
   LOAD MOVIES
===================================================== */

async function loadMovies() {

    try {

        const response =
            await fetch(
                "index.php?action=movies"
            );

        const data =
            await response.json();

        if (
            !Array.isArray(data)
        ) {

            throw new Error(
                data.message ||
                "Invalid movie data"
            );
        }

        movies = data;


        const grid =
            document.getElementById(
                "movieGrid"
            );


        grid.innerHTML =
            movies.map(movie => `

                <div class="movie-card">

                    <div>

                        <div class="movie-title">
                            ${movie.title}
                        </div>

                        <div class="movie-info">

                            <span class="badge">
                                ${movie.genre}
                            </span>

                            <br><br>

                            🌐 ${movie.language}
                            |
                            ⏱️ ${movie.duration}

                            <br>

                            ⭐ Rating:
                            ${movie.rating}

                        </div>

                    </div>


                    <div>

                        <div
                            style="
                                font-weight:bold;
                                margin-bottom:8px;
                            "
                        >
                            ₹${movie.price}
                        </div>


                        <button
                            class="btn"
                            onclick="selectMovie(${movie.id})"
                        >
                            Book Now
                        </button>

                    </div>

                </div>

            `).join("");


    } catch (error) {

        console.error(error);

        document.getElementById(
            "movieGrid"
        ).innerHTML = `

            <p style="color:red;">
                Unable to load movies.
                <br><br>
                ${error.message}
            </p>

        `;
    }
}


/* =====================================================
   SELECT MOVIE
===================================================== */

function selectMovie(movieId) {

    selectedMovie =
        movies.find(
            movie =>
                Number(movie.id) ===
                Number(movieId)
        );


    if (!selectedMovie) {

        alert(
            "Movie not found."
        );

        return;
    }


    document.getElementById(
        "selectedMovieTitle"
    ).innerText =
        `Book Tickets for ${selectedMovie.title}`;


    document.getElementById(
        "ticketPrice"
    ).innerText =
        selectedMovie.price;


    document.getElementById(
        "movie-section"
    ).style.display =
        "none";


    document.getElementById(
        "booking-section"
    ).style.display =
        "block";


    generateSeats();

    updateSummary();
}


/* =====================================================
   LOAD SEATS
===================================================== */

async function generateSeats() {

    const grid =
        document.getElementById(
            "seatsGrid"
        );

    grid.innerHTML = "";

    selectedSeats = [];


    try {

        const response =
            await fetch(
                `index.php?action=seats&movie_id=${selectedMovie.id}`
            );


        const bookedSeats =
            await response.json();


        for (
            let i = 1;
            i <= 32;
            i++
        ) {

            const isBooked =
                bookedSeats.includes(i);


            const seat =
                document.createElement(
                    "div"
                );


            seat.className =
                "seat" +
                (
                    isBooked
                        ? " booked"
                        : ""
                );


            seat.innerText = i;


            if (!isBooked) {

                seat.onclick =
                    function() {

                        toggleSeat(
                            i,
                            seat
                        );

                    };
            }


            grid.appendChild(seat);
        }


    } catch (error) {

        console.error(error);

        alert(
            "Unable to load seats."
        );
    }
}


/* =====================================================
   TOGGLE SEAT
===================================================== */

function toggleSeat(
    seatNumber,
    element
) {

    if (
        selectedSeats.includes(
            seatNumber
        )
    ) {

        selectedSeats =
            selectedSeats.filter(
                seat =>
                    seat !== seatNumber
            );

        element.classList.remove(
            "selected"
        );

    } else {

        selectedSeats.push(
            seatNumber
        );

        element.classList.add(
            "selected"
        );
    }


    updateSummary();
}


/* =====================================================
   UPDATE SUMMARY
===================================================== */

function updateSummary() {

    document.getElementById(
        "selectedSeatsText"
    ).innerText =
        selectedSeats.length > 0
            ? selectedSeats.join(", ")
            : "None";


    const price =
        selectedMovie
            ? Number(
                selectedMovie.price
              )
            : 0;


    const total =
        selectedSeats.length *
        price;


    document.getElementById(
        "totalAmount"
    ).innerText =
        total;
}


/* =====================================================
   CONFIRM BOOKING
===================================================== */

async function confirmBooking() {

    const name =
        document.getElementById(
            "custName"
        ).value.trim();


    const phone =
        document.getElementById(
            "custPhone"
        ).value.trim();


    const email =
        document.getElementById(
            "custEmail"
        ).value.trim();


    const showTime =
        document.getElementById(
            "showTimeSelect"
        ).value;


    const paymentMethod =
        document.getElementById(
            "paymentMethod"
        ).value;


    if (
        !name ||
        !phone ||
        !email
    ) {

        alert(
            "Please fill in all customer details."
        );

        return;
    }


    if (
        selectedSeats.length === 0
    ) {

        alert(
            "Please select at least one seat."
        );

        return;
    }


    const formData =
        new FormData();


    formData.append(
        "name",
        name
    );


    formData.append(
        "phone",
        phone
    );


    formData.append(
        "email",
        email
    );


    formData.append(
        "movie_id",
        selectedMovie.id
    );


    formData.append(
        "show_time",
        showTime
    );


    formData.append(
        "payment_method",
        paymentMethod
    );


    selectedSeats.forEach(
        seat => {

            formData.append(
                "seats[]",
                seat
            );

        }
    );


    try {

        const response =
            await fetch(
                "index.php?action=book",
                {
                    method: "POST",
                    body: formData
                }
            );


        const result =
            await response.json();


        if (
            !result.success
        ) {

            alert(
                "❌ " +
                result.message
            );

            generateSeats();

            return;
        }


        alert(

            "🎉 Booking Successful!" +

            "\n\nBooking ID: " +
            result.booking_id +

            "\nCustomer: " +
            name +

            "\nMovie: " +
            result.movie +

            "\nSeats: " +
            result.seats.join(", ") +

            "\nTotal Paid: ₹" +
            result.total

        );


        cancelBooking();

        loadMovies();


    } catch (error) {

        console.error(error);

        alert(
            "Booking failed. " +
            error.message
        );
    }
}


/* =====================================================
   CANCEL
===================================================== */

function cancelBooking() {

    document.getElementById(
        "booking-section"
    ).style.display =
        "none";


    document.getElementById(
        "movie-section"
    ).style.display =
        "block";


    selectedMovie = null;

    selectedSeats = [];
}


/* =====================================================
   START
===================================================== */

loadMovies();

</script>


</body>

</html>
