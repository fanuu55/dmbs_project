let movies = [];

let selectedMovie = null;

let selectedSeats = [];


// ======================================
// LOAD MOVIES
// ======================================

async async function loadMovies() {

    try {

        const response = await fetch("get_movies.php");

        if (!response.ok) {
            throw new Error(
                "HTTP error: " + response.status
            );
        }

        const data = await response.json();

        console.log("Movies received:", data);

        if (!Array.isArray(data)) {
            throw new Error(
                "PHP did not return a movie array"
            );
        }

        movies = data;

        const grid =
            document.getElementById("movieGrid");

        grid.innerHTML = movies.map(movie => `
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

                        ⭐ Rating: ${movie.rating}
                    </div>
                </div>

                <div>
                    <div style="font-weight: bold; margin-bottom: 8px;">
                        ₹${movie.price}
                    </div>

                    <button
                        class="btn"
                        onclick="selectMovie(${movie.id})">
                        Book Now
                    </button>
                </div>

            </div>
        `).join("");

    } catch (error) {

        console.error("Movie loading error:", error);

        alert(
            "Unable to load movies. Check the browser console."
        );
    }
}


// ======================================
// SELECT MOVIE
// ======================================

function selectMovie(movieId) {

    selectedMovie =
        movies.find(movie => movie.id == movieId);

    if (!selectedMovie) {
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
    ).style.display = "none";

    document.getElementById(
        "booking-section"
    ).style.display = "block";

    generateSeats();

    updateSummary();
}


// ======================================
// LOAD SEATS FROM PHP / MYSQL
// ======================================

async function generateSeats() {

    const grid =
        document.getElementById("seatsGrid");

    grid.innerHTML = "";

    selectedSeats = [];

    try {

        const response =
            await fetch(
                `get_seats.php?movie_id=${selectedMovie.id}`
            );

        const bookedSeats =
            await response.json();

        for (let i = 1; i <= 32; i++) {

            const isBooked =
                bookedSeats.includes(i);

            const seat =
                document.createElement("div");

            seat.className =
                `seat ${isBooked ? "booked" : ""}`;

            seat.innerText = i;

            if (!isBooked) {

                seat.onclick = () =>
                    toggleSeat(i, seat);
            }

            grid.appendChild(seat);
        }

    } catch (error) {

        console.error(error);

        alert("Unable to load seats.");
    }
}


// ======================================
// SELECT / UNSELECT SEAT
// ======================================

function toggleSeat(seatNum, element) {

    if (selectedSeats.includes(seatNum)) {

        selectedSeats =
            selectedSeats.filter(
                seat => seat !== seatNum
            );

        element.classList.remove("selected");

    } else {

        selectedSeats.push(seatNum);

        element.classList.add("selected");
    }

    updateSummary();
}


// ======================================
// UPDATE TOTAL
// ======================================

function updateSummary() {

    document.getElementById(
        "selectedSeatsText"
    ).innerText =
        selectedSeats.length > 0
            ? selectedSeats.join(", ")
            : "None";

    const price =
        selectedMovie
            ? Number(selectedMovie.price)
            : 0;

    const total =
        selectedSeats.length * price;

    document.getElementById(
        "totalAmount"
    ).innerText = total;
}


// ======================================
// CONFIRM BOOKING
// ======================================

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

    const paymentMethod =
        document.getElementById(
            "paymentMethod"
        ).value;


    // Validate customer details

    if (!name || !phone || !email) {

        alert(
            "Please fill in all customer details."
        );

        return;
    }


    // Validate seats

    if (selectedSeats.length === 0) {

        alert(
            "Please select at least one seat."
        );

        return;
    }


    // Create form data

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
        "payment_method",
        paymentMethod
    );


    // Add seats

    selectedSeats.forEach(seat => {

        formData.append(
            "seats[]",
            seat
        );

    });


    try {

        const response =
            await fetch(
                "book.php",
                {
                    method: "POST",
                    body: formData
                }
            );


        const result =
            await response.json();


        if (!result.success) {

            alert(
                "❌ " + result.message
            );

            // Reload seats because another
            // customer may have booked one.

            generateSeats();

            return;
        }


        alert(
            `🎉 Booking Successful!\n\n` +

            `Booking ID: ${result.booking_id}\n` +

            `Customer: ${name}\n` +

            `Movie: ${result.movie}\n` +

            `Seats: ${result.seats.join(", ")}\n` +

            `Total Paid: ₹${result.total}`
        );


        cancelBooking();

        loadMovies();


    } catch (error) {

        console.error(error);

        alert(
            "Something went wrong while booking."
        );
    }
}


// ======================================
// CANCEL BOOKING
// ======================================

function cancelBooking() {

    document.getElementById(
        "booking-section"
    ).style.display = "none";

    document.getElementById(
        "movie-section"
    ).style.display = "block";

    selectedMovie = null;

    selectedSeats = [];
}


// ======================================
// INITIAL LOAD
// ======================================

loadMovies();
