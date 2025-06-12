<div class="ui padded height grid stackable">
  <div class="eight wide column">
    <form class="ui form coupon-editor" style="width:100%;">
    <h4 class="ui dividing header">Create Coupon</h4>
      <div class="two fields">
        <div class="field padded">
          <label>Course</label>
          <div class="ui padded search selection dropdown courses">
            <div class="text"></div>
          </div>
        </div>
        <div class="field">
          <label>Expiry</label>
          <div class="ui calendar expiry">
            <div class="ui input left icon">
              <i class="calendar icon"></i>
              <input type="text" placeholder="Date">
            </div>
          </div>
        </div>
      </div>
      <div class="field">
        <div class="ui toggle checkbox global-coupon">
          <input type="checkbox" name="public">
          <label>Global coupon</label>
        </div>
      </div>
      <div class="field">
        <label>Email</label>
        <input type="text" name="email" placeholder="Email">
      </div>
      <div class="two fields">
        <div class="field">
          <label>Price</label>
          <input type="number" name="price" placeholder="Special price">
        </div>
        <div class="field">
          <label>Code</label>
          <input type="text" name="code" placeholder="Code" style="text-transform:uppercase" maxlength="50">
        </div>
      </div>
      <div class="field">
        <label>*CUSTOMER FACING* message</label>
        <input type="text" name="comment" placeholder="Comment">
      </div>
      <button class="ui primary button send" type="submit">Create coupon</button>
      <button class="ui button cancel-edit hidden">Cancel</button>
      <div class="ui vertical segment">
        <p class="ui text coupon-result">&nbsp;</p>
      </div>
    </form>
  </div>
  <div class="eight wide column">
    <table class="ui celled table coupons">
      <thead>
        <tr>
          <th>Code</th>
          <th>Course</th>
          <th>Global</th>
          <th>Email</th>
          <th>Price</th>
          <th>Expires</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td colspan="7">No results</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>