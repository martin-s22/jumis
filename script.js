document.addEventListener('DOMContentLoaded', () => {
    fetch('retrieved-offers.json')
        .then(response => response.json())
        .then(offersData => {
            const checklistContainer = document.getElementById('offer-checklist');
            offersData.offers.forEach(offer => {
                const offerElement = createOfferTableForOffers(offer);
                checklistContainer.appendChild(offerElement);
            });
        });

    function createOfferTableForOffers(offer) {
        // ... (Your JavaScript function) ...
    }

    document.getElementById("move-selected").addEventListener("click", function(){
        const selectedOffers = [];
        document.querySelectorAll('#offer-checklist input[type="checkbox"]:checked').forEach(checkbox => {
            selectedOffers.push(checkbox.value);
        });
        fetch("move_offers.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: "selected_offers=" + JSON.stringify(selectedOffers)
        }).then(response => response.json()).then(data => {
            if(data.success){
                alert("Offers moved successfully");
                window.location.reload();
            } else {
                alert("Error moving offers");
            }
        });
    });
});